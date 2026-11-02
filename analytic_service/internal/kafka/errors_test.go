package kafka

import (
	"context"
	"errors"
	"testing"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type fakeDLQ struct {
	published []kafka.Message
	topics    []string
	err       error
	closed    bool
}

func (f *fakeDLQ) Publish(_ context.Context, topic string, msg kafka.Message, _ error) error {
	f.topics = append(f.topics, topic)
	f.published = append(f.published, msg)
	return f.err
}

func (f *fakeDLQ) Close() error {
	f.closed = true
	return nil
}

func TestPermanentClassification(t *testing.T) {
	t.Parallel()

	assert.False(t, isPermanent(nil))
	assert.False(t, isPermanent(errors.New("network down")))
	assert.True(t, isPermanent(Permanent(errors.New("bad payload"))))
	assert.True(t, isPermanent(Permanentf("bad %s", "payload")))

	sentinel := errors.New("sentinel")
	assert.True(t, errors.Is(Permanent(sentinel), sentinel))
}

func TestDecodeEvent(t *testing.T) {
	t.Parallel()

	var ev userRegisteredEvent

	ok, err := decodeEvent(kafka.Message{}, &ev)
	require.NoError(t, err)
	assert.False(t, ok)

	ok, err = decodeEvent(kafka.Message{Value: []byte(`{"user_id":1}`)}, &ev)
	require.NoError(t, err)
	assert.True(t, ok)
	assert.Equal(t, uint64(1), ev.UserID.u64())

	_, err = decodeEvent(kafka.Message{Value: []byte(`not json`)}, &ev)
	require.Error(t, err)
	assert.True(t, isPermanent(err))
}

func TestDLQSuffixApplied(t *testing.T) {
	t.Parallel()

	cfg := Config{Brokers: []string{"localhost:9092"}, DLQSuffix: ".dlq"}.withDefaults()
	assert.Equal(t, ".dlq", cfg.DLQSuffix)
	assert.Equal(t, 500, cfg.BatchMaxRows)
	assert.Equal(t, "user.events.dlq", "user.events"+cfg.DLQSuffix)
}
