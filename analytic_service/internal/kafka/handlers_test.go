package kafka

import (
	"context"
	"log/slog"
	"testing"
	"time"

	"analytic_service/internal/store"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type mockEventStore struct {
	users       []store.UserRegistration
	orders      []store.OrderFunnelEvent
	lines       []store.OrderLineItem
	products    []store.ProductDim
	searches    []store.SearchQuery
	dims        map[uint64]store.ProductDim
	lookupErr   error
	insertErr   error
	lookupCalls []uint64
}

func (m *mockEventStore) InsertUserRegistrations(_ context.Context, rows []store.UserRegistration) error {
	if m.insertErr != nil {
		return m.insertErr
	}
	m.users = append(m.users, rows...)
	return nil
}

func (m *mockEventStore) InsertOrderFunnelEvents(_ context.Context, rows []store.OrderFunnelEvent) error {
	if m.insertErr != nil {
		return m.insertErr
	}
	m.orders = append(m.orders, rows...)
	return nil
}

func (m *mockEventStore) InsertOrderLineItems(_ context.Context, rows []store.OrderLineItem) error {
	if m.insertErr != nil {
		return m.insertErr
	}
	m.lines = append(m.lines, rows...)
	return nil
}

func (m *mockEventStore) InsertProductDims(_ context.Context, rows []store.ProductDim) error {
	if m.insertErr != nil {
		return m.insertErr
	}
	m.products = append(m.products, rows...)
	return nil
}

func (m *mockEventStore) InsertSearchQueries(_ context.Context, rows []store.SearchQuery) error {
	if m.insertErr != nil {
		return m.insertErr
	}
	m.searches = append(m.searches, rows...)
	return nil
}

func (m *mockEventStore) LookupProductDim(_ context.Context, productID uint64) (store.ProductDim, bool, error) {
	m.lookupCalls = append(m.lookupCalls, productID)
	if m.lookupErr != nil {
		return store.ProductDim{}, false, m.lookupErr
	}
	if m.dims == nil {
		return store.ProductDim{}, false, nil
	}
	dim, ok := m.dims[productID]
	return dim, ok, nil
}

func flushSink(t *testing.T, sink consumerSink) {
	t.Helper()
	require.NoError(t, sink.flush(context.Background()))
}

func TestUserSink(t *testing.T) {
	t.Parallel()

	t.Run("valid", func(t *testing.T) {
		st := &mockEventStore{}
		sink := newUserSink(st, 100)
		require.NoError(t, sink.handle(context.Background(), kafka.Message{
			Topic: "user.events",
			Value: []byte(`{"event_type":"user.registered","user_id":10,"email":"a@b.c","name":"Ann","registered_at":"2024-01-02T00:00:00Z"}`),
		}))
		flushSink(t, sink)
		require.Len(t, st.users, 1)
		assert.Equal(t, uint64(10), st.users[0].UserID)
		assert.Equal(t, "a@b.c", st.users[0].Email)
	})

	t.Run("missing user_id is permanent", func(t *testing.T) {
		st := &mockEventStore{}
		sink := newUserSink(st, 100)
		err := sink.handle(context.Background(), kafka.Message{Value: []byte(`{"event_type":"user.registered"}`)})
		require.Error(t, err)
		assert.True(t, isPermanent(err))
		flushSink(t, sink)
		assert.Empty(t, st.users)
	})

	t.Run("invalid json is permanent", func(t *testing.T) {
		st := &mockEventStore{}
		sink := newUserSink(st, 100)
		err := sink.handle(context.Background(), kafka.Message{Value: []byte(`{bad`)})
		require.Error(t, err)
		assert.True(t, isPermanent(err))
	})

	t.Run("other event type ignored", func(t *testing.T) {
		st := &mockEventStore{}
		sink := newUserSink(st, 100)
		require.NoError(t, sink.handle(context.Background(), kafka.Message{
			Value: []byte(`{"event_type":"user.deleted","user_id":1}`),
		}))
		flushSink(t, sink)
		assert.Empty(t, st.users)
	})
}

func TestOrderSinkEventTypes(t *testing.T) {
	t.Parallel()

	cases := []struct {
		eventType string
		wantShort string
		wantLines bool
	}{
		{"order.created", "created", true},
		{"order.paid", "paid", false},
		{"order.shipped", "completed", false},
		{"order.cancelled", "cancelled", false},
	}

	for _, tc := range cases {
		t.Run(tc.eventType, func(t *testing.T) {
			t.Parallel()
			st := &mockEventStore{}
			sink := newOrderSink(st, 100, slog.Default())
			body := `{"event_type":"` + tc.eventType + `","order_id":5,"user_id":1,"total_amount":"12.50","items":[{"product_id":99,"quantity":1,"unit_price":"12.50","store_id":1,"store_name":"S","category_id":2,"category_name":"C","category_slug":"c"}]}`
			require.NoError(t, sink.handle(context.Background(), kafka.Message{
				Topic: "order.events",
				Value: []byte(body),
			}))
			flushSink(t, sink)
			require.Len(t, st.orders, 1)
			assert.Equal(t, tc.wantShort, st.orders[0].EventType)
			assert.Equal(t, "12.5", st.orders[0].TotalAmount.String())
			if tc.wantLines {
				require.Len(t, st.lines, 1)
				assert.Equal(t, uint64(99), st.lines[0].ProductID)
			} else {
				assert.Empty(t, st.lines)
			}
		})
	}
}

func TestOrderSinkZeroOrderIDIsPermanent(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{}
	sink := newOrderSink(st, 100, slog.Default())
	err := sink.handle(context.Background(), kafka.Message{
		Value: []byte(`{"event_type":"order.created","order_id":0}`),
	})
	require.Error(t, err)
	assert.True(t, isPermanent(err))
	flushSink(t, sink)
	assert.Empty(t, st.orders)
}

func TestOrderSinkSkipZeroProductAndDimBackfill(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{
		dims: map[uint64]store.ProductDim{
			7: {
				ProductID:    7,
				StoreID:      3,
				StoreName:    "DimStore",
				CategoryID:   4,
				CategoryName: "DimCat",
				CategorySlug: "dim-cat",
			},
		},
	}
	sink := newOrderSink(st, 100, slog.Default())

	require.NoError(t, sink.handle(context.Background(), kafka.Message{
		Topic: "order.events",
		Value: []byte(`{
			"event_type":"order.created",
			"order_id":100,
			"user_id":1,
			"total_amount":"10.00",
			"items":[
				{"product_id":0,"quantity":1},
				{"product_id":7,"quantity":2,"unit_price":"5.00"}
			]
		}`),
	}))
	flushSink(t, sink)
	require.Len(t, st.orders, 1)
	require.Len(t, st.lines, 1)
	assert.Equal(t, []uint64{7}, st.lookupCalls)
	assert.Equal(t, "DimStore", st.lines[0].StoreName)
	assert.Equal(t, uint64(3), st.lines[0].StoreID)
	assert.Equal(t, uint64(4), st.lines[0].CategoryID)
	assert.Equal(t, "DimCat", st.lines[0].CategoryName)
	assert.Equal(t, "dim-cat", st.lines[0].CategorySlug)
	assert.Equal(t, uint32(2), st.lines[0].Quantity)
}

func TestOrderSinkNoDimLookupWhenComplete(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{dims: map[uint64]store.ProductDim{}}
	sink := newOrderSink(st, 100, slog.Default())
	require.NoError(t, sink.handle(context.Background(), kafka.Message{
		Value: []byte(`{
			"event_type":"order.created",
			"order_id":101,
			"items":[{
				"product_id":8,
				"store_id":1,
				"store_name":"S",
				"category_id":2,
				"category_name":"C",
				"category_slug":"c",
				"quantity":1,
				"unit_price":"1.00"
			}]
		}`),
	}))
	flushSink(t, sink)
	assert.Empty(t, st.lookupCalls)
	require.Len(t, st.lines, 1)
	assert.Equal(t, "S", st.lines[0].StoreName)
}

func TestProductSinkNestedCategoryStore(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{}
	sink := newProductSink(st, 100)
	require.NoError(t, sink.handle(context.Background(), kafka.Message{
		Topic:   "product.created",
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("product.created")}},
		Value: []byte(`{
			"data":{
				"id":55,
				"category":{"id":9,"name":"Shoes","slug":"shoes"},
				"store":{"id":2,"name":"Main"}
			}
		}`),
	}))
	flushSink(t, sink)
	require.Len(t, st.products, 1)
	p := st.products[0]
	assert.Equal(t, uint64(55), p.ProductID)
	assert.Equal(t, uint64(9), p.CategoryID)
	assert.Equal(t, "Shoes", p.CategoryName)
	assert.Equal(t, "shoes", p.CategorySlug)
	assert.Equal(t, uint64(2), p.StoreID)
	assert.Equal(t, "Main", p.StoreName)
}

func TestProductSinkMissingIDIsPermanent(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{}
	sink := newProductSink(st, 100)
	err := sink.handle(context.Background(), kafka.Message{
		Value: []byte(`{"event_type":"product.updated","data":{}}`),
	})
	require.Error(t, err)
	assert.True(t, isPermanent(err))

	err = sink.handle(context.Background(), kafka.Message{
		Value: []byte(`{"event_type":"product.updated"}`),
	})
	require.Error(t, err)
	assert.True(t, isPermanent(err))
	flushSink(t, sink)
	assert.Empty(t, st.products)
}

func TestSearchSinkSkipAndNestedQuery(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{}
	sink := newSearchSink(st, 100)
	require.NoError(t, sink.handle(context.Background(), kafka.Message{
		Headers: []kafka.Header{{Key: "skip-analytics", Value: []byte("1")}},
		Value:   []byte(`{"event_type":"search.performed","data":{"query":"boots"}}`),
	}))
	flushSink(t, sink)
	assert.Empty(t, st.searches)

	require.NoError(t, sink.handle(context.Background(), kafka.Message{
		Value: []byte(`{"event_type":"search.performed","timestamp":"2024-03-01T10:00:00Z","data":{"query":"boots","results_count":3,"sort":"price","filters":{"color":"red"}}}`),
	}))
	flushSink(t, sink)
	require.Len(t, st.searches, 1)
	assert.Equal(t, "boots", st.searches[0].Query)
	assert.Equal(t, uint32(3), st.searches[0].ResultsCount)
	assert.Equal(t, "price", st.searches[0].Sort)
	assert.Contains(t, st.searches[0].Filters, "color")
	assert.True(t, st.searches[0].OccurredAt.Equal(time.Date(2024, 3, 1, 10, 0, 0, 0, time.UTC)))
}

func TestSinkBatchesUntilFull(t *testing.T) {
	t.Parallel()

	st := &mockEventStore{}
	sink := newUserSink(st, 2)
	msg := kafka.Message{Value: []byte(`{"event_type":"user.registered","user_id":1}`)}

	require.NoError(t, sink.handle(context.Background(), msg))
	assert.False(t, sink.full())
	assert.Empty(t, st.users) // not flushed yet

	require.NoError(t, sink.handle(context.Background(), msg))
	assert.True(t, sink.full())

	flushSink(t, sink)
	assert.Len(t, st.users, 2)
	assert.False(t, sink.full())
}
