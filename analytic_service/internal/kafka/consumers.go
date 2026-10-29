package kafka

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"analytic_service/internal/store"

	"github.com/segmentio/kafka-go"
	"golang.org/x/sync/errgroup"
)

type UserRegistrar interface {
	InsertUserRegistration(ctx context.Context, row store.UserRegistration) error
}

type OrderIngester interface {
	InsertOrderFunnelEvent(ctx context.Context, row store.OrderFunnelEvent) error
	InsertOrderLineItems(ctx context.Context, rows []store.OrderLineItem) error
	LookupProductDim(ctx context.Context, productID uint64) (store.ProductDim, bool, error)
}

type ProductDimStore interface {
	InsertProductDim(ctx context.Context, row store.ProductDim) error
}

type SearchRecorder interface {
	InsertSearchQuery(ctx context.Context, row store.SearchQuery) error
}

// EventStore composes all ingest ports (convenient wiring for *store.Store).
type EventStore interface {
	UserRegistrar
	OrderIngester
	ProductDimStore
	SearchRecorder
}

type Consumers struct {
	brokers  []string
	users    UserRegistrar
	orders   OrderIngester
	products ProductDimStore
	search   SearchRecorder
	log      *slog.Logger
}

func NewConsumers(brokers []string, st EventStore, log *slog.Logger) *Consumers {
	if log == nil {
		log = slog.Default()
	}
	return &Consumers{
		brokers:  brokers,
		users:    st,
		orders:   st,
		products: st,
		search:   st,
		log:      log,
	}
}

type subscription struct {
	GroupID string
	Topics  []string
	Handle  handlerFunc
}

func (c *Consumers) Run(ctx context.Context) error {
	subs := []subscription{
		{GroupID: "analytic-service-users-v3", Topics: []string{"user.events"}, Handle: c.handleUser},
		{GroupID: "analytic-service-orders-v5", Topics: []string{"order.events"}, Handle: c.handleOrder},
		{GroupID: "analytic-service-search-v3", Topics: []string{"search.events"}, Handle: c.handleSearch},
		{GroupID: "analytic-service-products-v1", Topics: []string{"product.events"}, Handle: c.handleProduct},
	}

	g, ctx := errgroup.WithContext(ctx)
	for _, sub := range subs {
		g.Go(func() error {
			return c.runReader(ctx, sub.GroupID, sub.Topics, sub.Handle)
		})
	}
	return g.Wait()
}

type handlerFunc func(ctx context.Context, msg kafka.Message) error

const maxKafkaMessageBytes = 10e6 // 10 MiB

func (c *Consumers) runReader(ctx context.Context, groupID string, topics []string, handle handlerFunc) error {
	cfg := kafka.ReaderConfig{
		Brokers:        c.brokers,
		GroupID:        groupID,
		MinBytes:       1,
		MaxBytes:       maxKafkaMessageBytes,
		MaxWait:        time.Second,
		StartOffset:    kafka.FirstOffset,
		CommitInterval: 0, // manual commit after successful insert
	}
	if len(topics) == 1 {
		cfg.Topic = topics[0]
	} else {
		cfg.GroupTopics = topics
	}

	r := kafka.NewReader(cfg)
	defer func() { _ = r.Close() }()

	c.log.Info("kafka consumer started", "group", groupID, "topics", topics)

	for {
		msg, err := r.FetchMessage(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return fmt.Errorf("%s fetch: %w", groupID, err)
		}

		// Retry the same message until success — do not FetchMessage again on error
		// (that would skip offsets and let later commits mark failed rows as done).
		for {
			if err := handle(ctx, msg); err != nil {
				c.log.Error("handle message failed",
					"group", groupID,
					"topic", msg.Topic,
					"partition", msg.Partition,
					"offset", msg.Offset,
					"err", err,
				)
				select {
				case <-ctx.Done():
					return nil
				case <-time.After(time.Second):
				}
				continue
			}
			break
		}

		if err := r.CommitMessages(ctx, msg); err != nil {
			return fmt.Errorf("%s commit: %w", groupID, err)
		}
	}
}

func (c *Consumers) handleUser(ctx context.Context, msg kafka.Message) error {
	body, err := parseJSONBody(msg)
	if err != nil {
		c.log.Warn("skip invalid user json", "err", err)
		return nil
	}
	et := resolveEventType(msg, body)
	if et != "user.registered" {
		return nil
	}

	registeredAt := parseTime(asString(body["registered_at"]), messageTime(msg))
	row := store.UserRegistration{
		UserID:       asUint64(body["user_id"]),
		Email:        asString(body["email"]),
		Name:         asString(body["name"]),
		RegisteredAt: registeredAt,
		KafkaProvenance: store.KafkaProvenance{
			KafkaTopic:     msg.Topic,
			KafkaPartition: kafkaPartition(msg.Partition),
			KafkaOffset:    msg.Offset,
		},
	}
	if row.UserID == 0 {
		c.log.Warn("skip user.registered without user_id")
		return nil
	}
	return c.users.InsertUserRegistration(ctx, row)
}

func (c *Consumers) handleProduct(ctx context.Context, msg kafka.Message) error {
	body, err := parseJSONBody(msg)
	if err != nil {
		c.log.Warn("skip invalid product json", "err", err)
		return nil
	}
	et := resolveEventType(msg, body)
	if et != "product.created" && et != "product.updated" {
		return nil
	}

	data, _ := body["data"].(map[string]any)
	if data == nil {
		c.log.Warn("skip product event without data")
		return nil
	}

	productID := asUint64(data["id"])
	if productID == 0 {
		c.log.Warn("skip product event without id", "event_type", et)
		return nil
	}

	categoryID := asUint64(data["category_id"])
	categoryName := ""
	categorySlug := ""
	if cat, ok := data["category"].(map[string]any); ok {
		if categoryID == 0 {
			categoryID = asUint64(cat["id"])
		}
		categoryName = asString(cat["name"])
		categorySlug = asString(cat["slug"])
	}

	storeID := asUint64(data["store_id"])
	storeName := ""
	if st, ok := data["store"].(map[string]any); ok {
		if storeID == 0 {
			storeID = asUint64(st["id"])
		}
		storeName = asString(st["name"])
	}

	return c.products.InsertProductDim(ctx, store.ProductDim{
		ProductID:    productID,
		StoreID:      storeID,
		StoreName:    storeName,
		CategoryID:   categoryID,
		CategoryName: categoryName,
		CategorySlug: categorySlug,
		KafkaProvenance: store.KafkaProvenance{
			KafkaTopic:     msg.Topic,
			KafkaPartition: kafkaPartition(msg.Partition),
			KafkaOffset:    msg.Offset,
		},
	})
}

func (c *Consumers) handleOrder(ctx context.Context, msg kafka.Message) error {
	body, err := parseJSONBody(msg)
	if err != nil {
		c.log.Warn("skip invalid order json", "err", err)
		return nil
	}
	et := resolveEventType(msg, body)

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

	occurredAtRaw := asString(body["occurred_at"])
	if occurredAtRaw == "" {
		occurredAtRaw = asString(body["created_at"])
	}
	occurredAt := parseTime(occurredAtRaw, messageTime(msg))

	row := store.OrderFunnelEvent{
		OrderID:     asUint64(body["order_id"]),
		UserID:      asUint64(body["user_id"]),
		EventType:   short,
		TotalAmount: asString(body["total_amount"]),
		Reason:      asString(body["reason"]),
		OccurredAt:  occurredAt,
		KafkaProvenance: store.KafkaProvenance{
			KafkaTopic:     msg.Topic,
			KafkaPartition: kafkaPartition(msg.Partition),
			KafkaOffset:    msg.Offset,
		},
	}
	if row.OrderID == 0 {
		c.log.Warn("skip order event without order_id", "event_type", et)
		return nil
	}
	if row.TotalAmount == "" {
		row.TotalAmount = "0.00"
	}
	if err := c.orders.InsertOrderFunnelEvent(ctx, row); err != nil {
		return err
	}
	if short != "created" {
		return nil
	}
	return c.insertOrderLineItems(ctx, msg, body, row.OrderID, occurredAt)
}

func (c *Consumers) insertOrderLineItems(ctx context.Context, msg kafka.Message, body map[string]any, orderID uint64, ts time.Time) error {
	rawItems, ok := body["items"].([]any)
	if !ok || len(rawItems) == 0 {
		return nil
	}
	lines := make([]store.OrderLineItem, 0, len(rawItems))
	for _, raw := range rawItems {
		item, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		productID := asUint64(item["product_id"])
		if productID == 0 {
			continue
		}
		unitPrice := asString(item["unit_price"])
		if unitPrice == "" {
			unitPrice = "0.00"
		}

		storeID := asUint64(item["store_id"])
		storeName := asString(item["store_name"])
		categoryID := asUint64(item["category_id"])
		categoryName := asString(item["category_name"])
		categorySlug := asString(item["category_slug"])

		missingCategoryID := categoryID == 0
		missingStoreName := storeName == ""
		missingCategoryLabels := categoryID != 0 && (categoryName == "" || categorySlug == "")
		needDim := missingCategoryID || missingStoreName || missingCategoryLabels
		if needDim {
			dim, found, err := c.orders.LookupProductDim(ctx, productID)
			if err != nil {
				return err
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
			OrderID:      orderID,
			ProductID:    productID,
			StoreID:      storeID,
			StoreName:    storeName,
			CategoryID:   categoryID,
			CategoryName: categoryName,
			CategorySlug: categorySlug,
			Quantity:     asUint32(item["quantity"]),
			UnitPrice:    unitPrice,
			OccurredAt:   ts,
			KafkaProvenance: store.KafkaProvenance{
				KafkaTopic:     msg.Topic,
				KafkaPartition: kafkaPartition(msg.Partition),
				KafkaOffset:    msg.Offset,
			},
		})
	}
	return c.orders.InsertOrderLineItems(ctx, lines)
}

func (c *Consumers) handleSearch(ctx context.Context, msg kafka.Message) error {
	body, err := parseJSONBody(msg)
	if err != nil {
		c.log.Warn("skip invalid search json", "err", err)
		return nil
	}
	et := resolveEventType(msg, body)
	if et != "search.performed" {
		return nil
	}
	if isSkipAnalytics(msg, body) {
		return nil
	}

	data, _ := body["data"].(map[string]any)
	if data == nil {
		data = map[string]any{}
	}

	filtersRaw := "{}"
	if f, ok := data["filters"]; ok && f != nil {
		b, err := json.Marshal(f)
		if err == nil {
			filtersRaw = string(b)
		}
	}

	query := asString(data["query"])
	if query == "" {
		query = strings.TrimSpace(asString(body["query"]))
	}

	searchOccurredAt := parseTime(asString(body["timestamp"]), messageTime(msg))

	row := store.SearchQuery{
		Query:        query,
		ResultsCount: asUint32(data["results_count"]),
		Filters:      filtersRaw,
		Sort:         asString(data["sort"]),
		OccurredAt:   searchOccurredAt,
		KafkaProvenance: store.KafkaProvenance{
			KafkaTopic:     msg.Topic,
			KafkaPartition: kafkaPartition(msg.Partition),
			KafkaOffset:    msg.Offset,
		},
	}
	return c.search.InsertSearchQuery(ctx, row)
}
