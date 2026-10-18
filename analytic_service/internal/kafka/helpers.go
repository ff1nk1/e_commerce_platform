package kafka

import (
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/segmentio/kafka-go"
)

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

func isSkipAnalytics(msg kafka.Message, body map[string]any) bool {
	raw := strings.ToLower(strings.TrimSpace(headerValue(msg, "skip-analytics")))
	if raw == "1" || raw == "true" || raw == "yes" {
		return true
	}
	switch v := body["skip_analytics"].(type) {
	case bool:
		return v
	case string:
		s := strings.ToLower(strings.TrimSpace(v))
		return s == "1" || s == "true" || s == "yes"
	default:
		return false
	}
}

func eventTypeFromBody(body map[string]any) string {
	if v, ok := body["event_type"].(string); ok && v != "" {
		return v
	}
	if v, ok := body["event"].(string); ok && v != "" {
		return v
	}
	return ""
}

func resolveEventType(msg kafka.Message, body map[string]any) string {
	if t := headerEventType(msg); t != "" {
		return t
	}
	return eventTypeFromBody(body)
}

func parseJSONBody(msg kafka.Message) (map[string]any, error) {
	var body map[string]any
	if len(msg.Value) == 0 {
		return map[string]any{}, nil
	}
	if err := json.Unmarshal(msg.Value, &body); err != nil {
		return nil, err
	}
	return body, nil
}

func asUint64(v any) uint64 {
	switch n := v.(type) {
	case float64:
		if n < 0 {
			return 0
		}
		return uint64(n)
	case int64:
		if n < 0 {
			return 0
		}
		return uint64(n)
	case int:
		if n < 0 {
			return 0
		}
		return uint64(n)
	case json.Number:
		i, _ := n.Int64()
		if i < 0 {
			return 0
		}
		return uint64(i)
	case string:
		i, _ := strconv.ParseUint(n, 10, 64)
		return i
	default:
		return 0
	}
}

func asUint32(v any) uint32 {
	return uint32(asUint64(v))
}

func asString(v any) string {
	switch s := v.(type) {
	case string:
		return s
	case float64:
		return strconv.FormatFloat(s, 'f', -1, 64)
	case nil:
		return ""
	default:
		b, err := json.Marshal(s)
		if err != nil {
			return ""
		}
		return string(b)
	}
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
