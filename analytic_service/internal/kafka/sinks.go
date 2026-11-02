package kafka

import (
	"context"
	"encoding/json"
	"log/slog"
	"time"

	"analytic_service/internal/store"

	"github.com/segmentio/kafka-go"
)

func provenance(msg kafka.Message) store.KafkaProvenance {
	return store.KafkaProvenance{
		KafkaTopic:     msg.Topic,
		KafkaPartition: kafkaPartition(msg.Partition),
		KafkaOffset:    msg.Offset,
	}
}

// --- user.events ---

type userSink struct {
	store EventStore
	buf   *store.Batcher[store.UserRegistration]
}

func newUserSink(st EventStore, maxRows int) *userSink {
	return &userSink{store: st, buf: store.NewBatcher(maxRows, st.InsertUserRegistrations)}
}

func (s *userSink) full() bool                      { return s.buf.Full() }
func (s *userSink) flush(ctx context.Context) error { return s.buf.Flush(ctx) }

func (s *userSink) handle(_ context.Context, msg kafka.Message) error {
	var ev userRegisteredEvent
	ok, err := decodeEvent(msg, &ev)
	if err != nil {
		return err
	}
	if !ok {
		return nil
	}
	if resolveEventType(msg, string(ev.EventType), string(ev.Event)) != "user.registered" {
		return nil
	}
	if ev.UserID.u64() == 0 {
		return Permanentf("user.registered without user_id")
	}
	s.buf.Add(store.UserRegistration{
		UserID:          ev.UserID.u64(),
		Email:           string(ev.Email),
		Name:            string(ev.Name),
		RegisteredAt:    parseTime(string(ev.RegisteredAt), messageTime(msg)),
		KafkaProvenance: provenance(msg),
	})
	return nil
}

// --- product.events ---

type productSink struct {
	store EventStore
	buf   *store.Batcher[store.ProductDim]
}

func newProductSink(st EventStore, maxRows int) *productSink {
	return &productSink{store: st, buf: store.NewBatcher(maxRows, st.InsertProductDims)}
}

func (s *productSink) full() bool                      { return s.buf.Full() }
func (s *productSink) flush(ctx context.Context) error { return s.buf.Flush(ctx) }

func (s *productSink) handle(_ context.Context, msg kafka.Message) error {
	var ev productEvent
	ok, err := decodeEvent(msg, &ev)
	if err != nil {
		return err
	}
	if !ok {
		return nil
	}
	et := resolveEventType(msg, string(ev.EventType), string(ev.Event))
	if et != "product.created" && et != "product.updated" {
		return nil
	}
	if ev.Data == nil {
		return Permanentf("%s without data", et)
	}
	if ev.Data.ID.u64() == 0 {
		return Permanentf("%s without product id", et)
	}

	categoryID := ev.Data.CategoryID.u64()
	categoryName, categorySlug := "", ""
	if ev.Data.Category != nil {
		if categoryID == 0 {
			categoryID = ev.Data.Category.ID.u64()
		}
		categoryName = string(ev.Data.Category.Name)
		categorySlug = string(ev.Data.Category.Slug)
	}

	storeID := ev.Data.StoreID.u64()
	storeName := ""
	if ev.Data.Store != nil {
		if storeID == 0 {
			storeID = ev.Data.Store.ID.u64()
		}
		storeName = string(ev.Data.Store.Name)
	}

	s.buf.Add(store.ProductDim{
		ProductID:       ev.Data.ID.u64(),
		StoreID:         storeID,
		StoreName:       storeName,
		CategoryID:      categoryID,
		CategoryName:    categoryName,
		CategorySlug:    categorySlug,
		KafkaProvenance: provenance(msg),
	})
	return nil
}

// --- order.events ---

type orderSink struct {
	store  EventStore
	funnel *store.Batcher[store.OrderFunnelEvent]
	lines  *store.Batcher[store.OrderLineItem]
	log    *slog.Logger
}

func newOrderSink(st EventStore, maxRows int, log *slog.Logger) *orderSink {
	return &orderSink{
		store:  st,
		funnel: store.NewBatcher(maxRows, st.InsertOrderFunnelEvents),
		lines:  store.NewBatcher(maxRows, st.InsertOrderLineItems),
		log:    log,
	}
}

func (s *orderSink) full() bool { return s.funnel.Full() || s.lines.Full() }

func (s *orderSink) flush(ctx context.Context) error {
	if err := s.funnel.Flush(ctx); err != nil {
		return err
	}
	return s.lines.Flush(ctx)
}

func (s *orderSink) handle(ctx context.Context, msg kafka.Message) error {
	var ev orderEvent
	ok, err := decodeEvent(msg, &ev)
	if err != nil {
		return err
	}
	if !ok {
		return nil
	}
	et := resolveEventType(msg, string(ev.EventType), string(ev.Event))

	var short string
	switch et {
	case "order.created":
		short = "created"
	case "order.paid":
		short = "paid"
	case "order.shipped":
		// Domain "shipped" is stored as funnel stage "completed".
		short = "completed"
	case "order.cancelled":
		short = "cancelled"
	default:
		return nil
	}

	if ev.OrderID.u64() == 0 {
		return Permanentf("%s without order_id", et)
	}

	occurredRaw := string(ev.OccurredAt)
	if occurredRaw == "" {
		occurredRaw = string(ev.CreatedAt)
	}
	occurredAt := parseTime(occurredRaw, messageTime(msg))

	// Resolve dim backfills before buffering anything: a transient lookup
	// failure must not leave a duplicate funnel row behind on retry.
	var lines []store.OrderLineItem
	if short == "created" && len(ev.Items) > 0 {
		var err error
		lines, err = s.buildLineItems(ctx, msg, ev.Items, ev.OrderID.u64(), occurredAt)
		if err != nil {
			return err
		}
	}

	s.funnel.Add(store.OrderFunnelEvent{
		OrderID:         ev.OrderID.u64(),
		UserID:          ev.UserID.u64(),
		EventType:       short,
		TotalAmount:     ev.TotalAmount.Decimal,
		Reason:          string(ev.Reason),
		OccurredAt:      occurredAt,
		KafkaProvenance: provenance(msg),
	})
	s.lines.AddAll(lines)
	return nil
}

func (s *orderSink) buildLineItems(ctx context.Context, msg kafka.Message, items []orderItem, orderID uint64, ts time.Time) ([]store.OrderLineItem, error) {
	lines := make([]store.OrderLineItem, 0, len(items))
	for _, item := range items {
		productID := item.ProductID.u64()
		if productID == 0 {
			continue
		}

		storeID := item.StoreID.u64()
		storeName := string(item.StoreName)
		categoryID := item.CategoryID.u64()
		categoryName := string(item.CategoryName)
		categorySlug := string(item.CategorySlug)

		missingCategoryID := categoryID == 0
		missingStoreName := storeName == ""
		missingCategoryLabels := categoryID != 0 && (categoryName == "" || categorySlug == "")
		if missingCategoryID || missingStoreName || missingCategoryLabels {
			dim, found, err := s.store.LookupProductDim(ctx, productID)
			if err != nil {
				return nil, err
			}
			if found {
				if storeID == 0 {
					storeID = dim.StoreID
				}
				if storeName == "" {
					storeName = dim.StoreName
				}
				if categoryID == 0 {
					categoryID = dim.CategoryID
				}
				if categoryName == "" {
					categoryName = dim.CategoryName
				}
				if categorySlug == "" {
					categorySlug = dim.CategorySlug
				}
			}
		}

		lines = append(lines, store.OrderLineItem{
			OrderID:         orderID,
			ProductID:       productID,
			StoreID:         storeID,
			StoreName:       storeName,
			CategoryID:      categoryID,
			CategoryName:    categoryName,
			CategorySlug:    categorySlug,
			Quantity:        item.Quantity.u32(),
			UnitPrice:       item.UnitPrice.Decimal,
			OccurredAt:      ts,
			KafkaProvenance: provenance(msg),
		})
	}
	return lines, nil
}

// --- search.events ---

type searchSink struct {
	store EventStore
	buf   *store.Batcher[store.SearchQuery]
}

func newSearchSink(st EventStore, maxRows int) *searchSink {
	return &searchSink{store: st, buf: store.NewBatcher(maxRows, st.InsertSearchQueries)}
}

func (s *searchSink) full() bool                      { return s.buf.Full() }
func (s *searchSink) flush(ctx context.Context) error { return s.buf.Flush(ctx) }

func (s *searchSink) handle(_ context.Context, msg kafka.Message) error {
	var ev searchEvent
	ok, err := decodeEvent(msg, &ev)
	if err != nil {
		return err
	}
	if !ok {
		return nil
	}
	if resolveEventType(msg, string(ev.EventType), string(ev.Event)) != "search.performed" {
		return nil
	}
	if isSkipAnalytics(msg, ev.SkipAnalytics) {
		return nil
	}

	var data searchData
	if ev.Data != nil {
		data = *ev.Data
	}

	filtersRaw := "{}"
	if len(data.Filters) > 0 && string(data.Filters) != "null" {
		if json.Valid(data.Filters) {
			filtersRaw = string(data.Filters)
		}
	}

	query := string(data.Query)
	if query == "" {
		query = string(ev.Query)
	}

	s.buf.Add(store.SearchQuery{
		Query:           query,
		ResultsCount:    data.ResultsCount.u32(),
		Filters:         filtersRaw,
		Sort:            string(data.Sort),
		OccurredAt:      parseTime(string(ev.Timestamp), messageTime(msg)),
		KafkaProvenance: provenance(msg),
	})
	return nil
}
