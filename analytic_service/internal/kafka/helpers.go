package kafka

import (
	"math"
	"strings"
	"time"

	"github.com/segmentio/kafka-go"
)

// kafkaPartition narrows a Kafka partition to int32. Partitions are small
// non-negative ids; values outside int32 are not valid partitions.
func kafkaPartition(partition int) int32 {
	if partition < 0 || partition > math.MaxInt32 {
		return 0
	}
	return int32(partition)
}

func headerValue(msg kafka.Message, key string) string {
	for _, h := range msg.Headers {
		if strings.EqualFold(h.Key, key) {
			return string(h.Value)
		}
	}
	return ""
}

func headerEventType(msg kafka.Message) string {
	return headerValue(msg, "event-type")
}

// resolveEventType returns the event type using the header first, then the body
// fields (typed struct field extraction happens before this call).
func resolveEventType(msg kafka.Message, eventType, event string) string {
	if t := headerEventType(msg); t != "" {
		return t
	}
	if eventType != "" {
		return eventType
	}
	return event
}

func truthy(raw string) bool {
	s := strings.ToLower(strings.TrimSpace(raw))
	return s == "1" || s == "true" || s == "yes"
}

// isSkipAnalytics reports whether the message opts out of analytic recording,
// via the header or the decoded body flag.
func isSkipAnalytics(msg kafka.Message, bodyValue flexBool) bool {
	return truthy(headerValue(msg, "skip-analytics")) || bool(bodyValue)
}

func parseTime(raw string, fallback time.Time) time.Time {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return fallback.UTC()
	}
	layouts := []string{
		time.RFC3339Nano,
		time.RFC3339,
		"2006-01-02T15:04:05Z07:00",
		"2006-01-02 15:04:05",
	}
	for _, layout := range layouts {
		if t, err := time.Parse(layout, raw); err == nil {
			return t.UTC()
		}
	}
	return fallback.UTC()
}

func messageTime(msg kafka.Message) time.Time {
	if !msg.Time.IsZero() {
		return msg.Time.UTC()
	}
	return time.Now().UTC()
}
