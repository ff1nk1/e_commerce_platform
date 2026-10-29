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
		Body    map[string]any    `json:"body"`
	}
	require.NoError(t, json.Unmarshal(raw, &fixture))

	assert.Equal(t, "product.events", fixture.Topic)
	assert.Equal(t, "product.updated", fixture.Body["event_type"])
	assert.Equal(t, "product.updated", fixture.Body["event"])

	headers := make([]kafka.Header, 0, len(fixture.Headers))
	for key, value := range fixture.Headers {
		headers = append(headers, kafka.Header{Key: key, Value: []byte(value)})
	}
	msg := kafka.Message{
		Topic:   fixture.Topic,
		Key:     []byte(fixture.Key),
		Headers: headers,
	}

	assert.Equal(t, "product.updated", resolveEventType(msg, fixture.Body))
}
