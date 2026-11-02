package kafka

import (
	"context"
	"fmt"
	"time"

	"github.com/segmentio/kafka-go"
)

// DLQPublisher routes poison-pill messages to a dead-letter topic so a single
// bad message cannot stall a partition forever.
type DLQPublisher interface {
	Publish(ctx context.Context, topic string, msg kafka.Message, reason error) error
	Close() error
}

// kafkaDLQ publishes dead letters with a kafka-go writer. It is safe for
// concurrent use (the four consumer loops share one publisher).
type kafkaDLQ struct {
	writer *kafka.Writer
}

// NewKafkaDLQ returns a DLQPublisher backed by the given brokers.
func NewKafkaDLQ(brokers []string) DLQPublisher {
	return &kafkaDLQ{writer: &kafka.Writer{
		Addr:                   kafka.TCP(brokers...),
		Balancer:               &kafka.LeastBytes{},
		AllowAutoTopicCreation: true,
		RequiredAcks:           kafka.RequireAll,
	}}
}

func (d *kafkaDLQ) Publish(ctx context.Context, topic string, msg kafka.Message, reason error) error {
	headers := make([]kafka.Header, 0, len(msg.Headers)+2)
	headers = append(headers, msg.Headers...)
	headers = append(headers,
		kafka.Header{Key: "dlq-reason", Value: []byte(reason.Error())},
		kafka.Header{Key: "dlq-failed-at", Value: []byte(time.Now().UTC().Format(time.RFC3339))},
	)
	// Preserve the original key/value/headers so the message can be replayed.
	err := d.writer.WriteMessages(ctx, kafka.Message{
		Topic:   topic,
		Key:     msg.Key,
		Value:   msg.Value,
		Headers: headers,
		Time:    time.Now().UTC(),
	})
	if err != nil {
		return fmt.Errorf("publish dlq %s: %w", topic, err)
	}
	return nil
}

func (d *kafkaDLQ) Close() error { return d.writer.Close() }
