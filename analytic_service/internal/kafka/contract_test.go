package kafka

import (
	"encoding/json"
	"os"
	"testing"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestProductUpdatedContractFixture(t *testing.T) {
	t.Parallel()

	raw, err := os.ReadFile("../../../packages/messaging/tests/fixtures/product-updated.json")
	require.NoError(t, err)

	var fixture struct {
		Topic   string            `json:"topic"`
		Key     string            `json:"key"`
		Headers map[string]string `json:"headers"`
		Body    productEvent      `json:"body"`
	}
	require.NoError(t, json.Unmarshal(raw, &fixture))

	assert.Equal(t, "product.events", fixture.Topic)
	assert.Equal(t, "product.updated", string(fixture.Body.EventType))
	assert.Equal(t, "product.updated", string(fixture.Body.Event))
	require.NotNil(t, fixture.Body.Data)
	assert.Equal(t, uint64(42), fixture.Body.Data.ID.u64())

	headers := make([]kafka.Header, 0, len(fixture.Headers))
	for key, value := range fixture.Headers {
		headers = append(headers, kafka.Header{Key: key, Value: []byte(value)})
	}
	msg := kafka.Message{
		Topic:   fixture.Topic,
		Key:     []byte(fixture.Key),
		Headers: headers,
	}

	var ev productEvent
	bodyBytes, err := json.Marshal(fixture.Body)
	require.NoError(t, err)
	require.NoError(t, json.Unmarshal(bodyBytes, &ev))
	assert.Equal(t, "product.updated", resolveEventType(msg, string(ev.EventType), string(ev.Event)))
}
