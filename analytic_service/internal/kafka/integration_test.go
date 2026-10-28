//go:build integration

package kafka

import (
	"context"
	"errors"
	"log/slog"
	"testing"
	"time"

	"analytic_service/internal/testutil"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func pingKafka(t *testing.T, brokers []string) {
	t.Helper()
	conn, err := kafka.Dial("tcp", brokers[0])
	if err != nil {
		t.Skipf("kafka unavailable at %s: %v", brokers[0], err)
	}
	_ = conn.Close()
}

func writeAndHandle(t *testing.T, brokers []string, topic string, msg kafka.Message, handle func(context.Context, kafka.Message) error) {
	t.Helper()

	conn, err := kafka.Dial("tcp", brokers[0])
	require.NoError(t, err)
	t.Cleanup(func() { _ = conn.Close() })
	err = conn.CreateTopics(kafka.TopicConfig{
		Topic:             topic,
		NumPartitions:     1,
		ReplicationFactor: 1,
	})
	if err != nil && !errors.Is(err, kafka.TopicAlreadyExists) {
		require.NoError(t, err)
	}

	w := &kafka.Writer{
		Addr:                   kafka.TCP(brokers...),
		Topic:                  topic,
		Balancer:               &kafka.LeastBytes{},
		AllowAutoTopicCreation: true,
	}
	t.Cleanup(func() { _ = w.Close() })

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	require.NoError(t, w.WriteMessages(ctx, msg))

	// Handlers read msg.Topic for kafka_* columns; Writer owns the publish topic.
	msg.Topic = topic
	require.NoError(t, handle(ctx, msg))
}

func TestIntegrationKafkaToClickHouse(t *testing.T) {
	brokers := testutil.KafkaBrokers()
	pingKafka(t, brokers)

	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)

	c := NewConsumers(brokers, st, slog.Default())
	ctx := context.Background()

	// product.created → product_dim
	writeAndHandle(t, brokers, "product.created", kafka.Message{
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("product.created")}},
		Value: []byte(`{
			"data":{
				"id":1001,
				"category":{"id":50,"name":"Bags","slug":"bags"},
				"store":{"id":8,"name":"Outlet"}
			}
		}`),
	}, c.handleProduct)

	dim, found, err := st.LookupProductDim(ctx, 1001)
	require.NoError(t, err)
	require.True(t, found)
	assert.Equal(t, "Outlet", dim.StoreName)
	assert.Equal(t, "bags", dim.CategorySlug)

	// order.created with partial item fields → dim backfill into order_line_items
	writeAndHandle(t, brokers, "order.events", kafka.Message{
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("order.created")}},
		Value: []byte(`{
			"order_id":5001,
			"user_id":42,
			"total_amount":"25.00",
			"occurred_at":"2024-08-01T10:00:00Z",
			"items":[{"product_id":1001,"quantity":1,"unit_price":"25.00"}]
		}`),
	}, c.handleOrder)

	var storeName string
	var categorySlug string
	err = conn.QueryRow(ctx, `
		SELECT store_name, category_slug
		FROM order_line_items FINAL
		WHERE order_id = 5001 AND product_id = 1001
		LIMIT 1
	`).Scan(&storeName, &categorySlug)
	require.NoError(t, err)
	assert.Equal(t, "Outlet", storeName)
	assert.Equal(t, "bags", categorySlug)

	var funnelType string
	err = conn.QueryRow(ctx, `
		SELECT event_type FROM order_funnel_events FINAL
		WHERE order_id = 5001 AND event_type = 'created'
		LIMIT 1
	`).Scan(&funnelType)
	require.NoError(t, err)
	assert.Equal(t, "created", funnelType)

	// paid does not write line items
	writeAndHandle(t, brokers, "order.events", kafka.Message{
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("order.paid")}},
		Value: []byte(`{
			"order_id":5001,
			"user_id":42,
			"total_amount":"25.00",
			"occurred_at":"2024-08-01T11:00:00Z",
			"items":[{"product_id":1001,"quantity":9,"unit_price":"25.00"}]
		}`),
	}, c.handleOrder)

	var lineCount uint64
	err = conn.QueryRow(ctx, `
		SELECT count() FROM order_line_items FINAL WHERE order_id = 5001
	`).Scan(&lineCount)
	require.NoError(t, err)
	assert.Equal(t, uint64(1), lineCount)

	// user.registered
	writeAndHandle(t, brokers, "user.events", kafka.Message{
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("user.registered")}},
		Value:   []byte(`{"user_id":42,"email":"u@x","name":"U","registered_at":"2024-08-01T09:00:00Z"}`),
	}, c.handleUser)

	var users uint64
	err = conn.QueryRow(ctx, `SELECT count() FROM user_registrations FINAL WHERE user_id = 42`).Scan(&users)
	require.NoError(t, err)
	assert.Equal(t, uint64(1), users)

	// search skip-analytics → no row
	writeAndHandle(t, brokers, "search.events", kafka.Message{
		Headers: []kafka.Header{
			{Key: "event-type", Value: []byte("search.performed")},
			{Key: "skip-analytics", Value: []byte("true")},
		},
		Value: []byte(`{"data":{"query":"secret","results_count":1}}`),
	}, c.handleSearch)

	writeAndHandle(t, brokers, "search.events", kafka.Message{
		Headers: []kafka.Header{{Key: "event-type", Value: []byte("search.performed")}},
		Value:   []byte(`{"timestamp":"2024-08-01T12:00:00Z","data":{"query":"visible","results_count":2}}`),
	}, c.handleSearch)

	var searches uint64
	err = conn.QueryRow(ctx, `
		SELECT count() FROM search_queries FINAL WHERE query = 'secret'
	`).Scan(&searches)
	require.NoError(t, err)
	assert.Equal(t, uint64(0), searches)

	err = conn.QueryRow(ctx, `
		SELECT count() FROM search_queries FINAL WHERE query = 'visible'
	`).Scan(&searches)
	require.NoError(t, err)
	assert.Equal(t, uint64(1), searches)
}
