package kafka

import (
	"encoding/json"
	"math"
	"strconv"
	"strings"

	"github.com/shopspring/decimal"
)

// The analytic consumers read domain events from Kafka. Producers are not
// strictly consistent about scalar encodings (ids may be numbers or strings,
// money may be a JSON number or a quoted decimal), so the decoder helpers below
// stay lenient on shape while keeping the handler code strictly typed.

// flexString unmarshals a JSON string, number, or bool into a string. Container
// shapes (object/array) are rejected so a malformed payload becomes a permanent
// error instead of silently decoding to "".
type flexString string

func (s *flexString) UnmarshalJSON(b []byte) error {
	if len(b) == 0 || string(b) == "null" {
		*s = ""
		return nil
	}
	switch b[0] {
	case '"':
		var v string
		if err := json.Unmarshal(b, &v); err != nil {
			return Permanentf("flexString: %w", err)
		}
		*s = flexString(v)
		return nil
	case '{', '[':
		return Permanentf("flexString: unexpected container value %s", string(b))
	default:
		*s = flexString(string(b))
		return nil
	}
}

// idValue unmarshals a JSON number or numeric string into a uint64. Malformed
// values (non-numeric, negative, or fractional) are permanent errors so the
// message is routed to the DLQ instead of being silently coerced to 0.
type idValue uint64

func (v *idValue) UnmarshalJSON(b []byte) error {
	s := strings.Trim(strings.TrimSpace(string(b)), `"`)
	if s == "" || s == "null" {
		*v = 0
		return nil
	}
	if i, err := strconv.ParseUint(s, 10, 64); err == nil {
		*v = idValue(i)
		return nil
	}
	f, err := strconv.ParseFloat(s, 64)
	if err != nil || f < 0 || f != math.Trunc(f) {
		return Permanentf("idValue: invalid id %q", s)
	}
	*v = idValue(uint64(f))
	return nil
}

func (v idValue) u64() uint64 { return uint64(v) }

func (v idValue) u32() uint32 {
	n := uint64(v)
	if n > math.MaxUint32 {
		return 0
	}
	return uint32(n)
}

// money unmarshals a JSON number or quoted decimal string into a decimal.
// Malformed amounts are permanent errors so one bad value is dead-lettered
// instead of silently poisoning a batch with a zeroed amount.
type money struct{ decimal.Decimal }

func (m *money) UnmarshalJSON(b []byte) error {
	if len(b) == 0 || string(b) == "null" {
		m.Decimal = decimal.Zero
		return nil
	}
	s := strings.Trim(strings.TrimSpace(string(b)), `"`)
	if s == "" {
		m.Decimal = decimal.Zero
		return nil
	}
	d, err := decimal.NewFromString(s)
	if err != nil {
		return Permanentf("money: invalid amount %q", s)
	}
	m.Decimal = d
	return nil
}

// flexBool unmarshals a JSON bool, number, or truthy string into a bool.
// Container shapes are rejected as permanent errors.
type flexBool bool

func (b *flexBool) UnmarshalJSON(raw []byte) error {
	s := strings.Trim(strings.TrimSpace(string(raw)), `"`)
	if s == "" || s == "null" {
		*b = false
		return nil
	}
	switch s {
	case "true", "1":
		*b = true
	case "false", "0":
		*b = false
	default:
		if raw[0] == '{' || raw[0] == '[' {
			return Permanentf("flexBool: unexpected container value %s", s)
		}
		*b = flexBool(truthy(s))
	}
	return nil
}

type userRegisteredEvent struct {
	EventType    flexString `json:"event_type"`
	Event        flexString `json:"event"`
	UserID       idValue    `json:"user_id"`
	Email        flexString `json:"email"`
	Name         flexString `json:"name"`
	RegisteredAt flexString `json:"registered_at"`
}

type orderItem struct {
	ProductID    idValue    `json:"product_id"`
	StoreID      idValue    `json:"store_id"`
	StoreName    flexString `json:"store_name"`
	CategoryID   idValue    `json:"category_id"`
	CategoryName flexString `json:"category_name"`
	CategorySlug flexString `json:"category_slug"`
	Quantity     idValue    `json:"quantity"`
	UnitPrice    money      `json:"unit_price"`
}

type orderEvent struct {
	EventType   flexString  `json:"event_type"`
	Event       flexString  `json:"event"`
	OrderID     idValue     `json:"order_id"`
	UserID      idValue     `json:"user_id"`
	TotalAmount money       `json:"total_amount"`
	Reason      flexString  `json:"reason"`
	OccurredAt  flexString  `json:"occurred_at"`
	CreatedAt   flexString  `json:"created_at"`
	Items       []orderItem `json:"items"`
}

type storeRef struct {
	ID   idValue    `json:"id"`
	Name flexString `json:"name"`
}

type categoryRef struct {
	ID   idValue    `json:"id"`
	Name flexString `json:"name"`
	Slug flexString `json:"slug"`
}

type productData struct {
	ID         idValue      `json:"id"`
	StoreID    idValue      `json:"store_id"`
	Store      *storeRef    `json:"store"`
	CategoryID idValue      `json:"category_id"`
	Category   *categoryRef `json:"category"`
}

type productEvent struct {
	EventType flexString   `json:"event_type"`
	Event     flexString   `json:"event"`
	Data      *productData `json:"data"`
}

type searchData struct {
	Query        flexString      `json:"query"`
	ResultsCount idValue         `json:"results_count"`
	Sort         flexString      `json:"sort"`
	Filters      json.RawMessage `json:"filters"`
}

type searchEvent struct {
	EventType     flexString  `json:"event_type"`
	Event         flexString  `json:"event"`
	SkipAnalytics flexBool    `json:"skip_analytics"`
	Timestamp     flexString  `json:"timestamp"`
	Query         flexString  `json:"query"`
	Data          *searchData `json:"data"`
}
