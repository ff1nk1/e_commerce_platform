package kafka

import (
	"testing"
	"time"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestResolveEventType(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name      string
		msg       kafka.Message
		eventType string
		event     string
		want      string
	}{
		{
			name: "header wins",
			msg: kafka.Message{Headers: []kafka.Header{
				{Key: "event-type", Value: []byte("user.registered")},
			}},
			eventType: "ignored",
			want:      "user.registered",
		},
		{
			name:      "body event_type",
			eventType: "user.registered",
			want:      "user.registered",
		},
		{
			name:  "body event",
			event: "search.performed",
			want:  "search.performed",
		},
		{
			name: "empty",
			want: "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			assert.Equal(t, tt.want, resolveEventType(tt.msg, tt.eventType, tt.event))
		})
	}
}

func TestIsSkipAnalytics(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name string
		msg  kafka.Message
		body flexBool
		want bool
	}{
		{
			name: "header true",
			msg: kafka.Message{Headers: []kafka.Header{
				{Key: "skip-analytics", Value: []byte("true")},
			}},
			want: true,
		},
		{
			name: "header yes case insensitive",
			msg: kafka.Message{Headers: []kafka.Header{
				{Key: "Skip-Analytics", Value: []byte("YES")},
			}},
			want: true,
		},
		{
			name: "body true",
			body: true,
			want: true,
		},
		{
			name: "false",
			body: false,
			want: false,
		},
		{
			name: "absent",
			want: false,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			assert.Equal(t, tt.want, isSkipAnalytics(tt.msg, tt.body))
		})
	}
}

func TestFlexBool(t *testing.T) {
	t.Parallel()

	cases := map[string]bool{
		`true`:    true,
		`"true"`:  true,
		`1`:       true,
		`"1"`:     true,
		`"yes"`:   true,
		`false`:   false,
		`0`:       false,
		`"false"`: false,
		`null`:    false,
	}
	for raw, want := range cases {
		var b flexBool
		require.NoError(t, b.UnmarshalJSON([]byte(raw)), raw)
		assert.Equal(t, want, bool(b), raw)
	}

	var b flexBool
	err := b.UnmarshalJSON([]byte(`{"nested":true}`))
	require.Error(t, err)
	assert.True(t, isPermanent(err))
}

func TestIDValue(t *testing.T) {
	t.Parallel()

	cases := map[string]uint64{
		`42`:         42,
		`"7"`:        7,
		`42.0`:       42,
		`null`:       0,
		`4294967296`: 4294967296,
	}
	for raw, want := range cases {
		var v idValue
		assert.NoError(t, v.UnmarshalJSON([]byte(raw)), raw)
		assert.Equal(t, want, v.u64(), raw)
	}

	// Malformed ids are permanent (poison -> DLQ) instead of silently zeroed.
	for _, raw := range []string{`-1`, `"abc"`, `1.5`} {
		var v idValue
		err := v.UnmarshalJSON([]byte(raw))
		require.Error(t, err, raw)
		assert.True(t, isPermanent(err), raw)
	}

	var v idValue
	assert.NoError(t, v.UnmarshalJSON([]byte(`4294967296`)))
	assert.Equal(t, uint32(0), v.u32())
}

func TestMoney(t *testing.T) {
	t.Parallel()

	cases := map[string]string{
		`12.34`:    "12.34",
		`"12.340"`: "12.34",
		`0`:        "0",
		`null`:     "0",
	}
	for raw, want := range cases {
		var m money
		assert.NoError(t, m.UnmarshalJSON([]byte(raw)), raw)
		assert.Equal(t, want, m.Decimal.String(), raw)
	}

	// Malformed amounts are permanent (poison -> DLQ) instead of silently zeroed.
	var m money
	err := m.UnmarshalJSON([]byte(`"bad"`))
	require.Error(t, err)
	assert.True(t, isPermanent(err))
}

func TestParseTime(t *testing.T) {
	t.Parallel()

	fallback := time.Date(2020, 1, 1, 0, 0, 0, 0, time.UTC)
	assert.Equal(t, fallback, parseTime("", fallback))
	assert.Equal(t, time.Date(2024, 3, 1, 10, 0, 0, 0, time.UTC),
		parseTime("2024-03-01T10:00:00Z", fallback))
	assert.Equal(t, fallback, parseTime("not-a-time", fallback))
}
