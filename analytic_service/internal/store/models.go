package store

import "time"

// KafkaProvenance tracks the source message for ClickHouse dedup / audit columns.
type KafkaProvenance struct {
	KafkaTopic     string
	KafkaPartition int32
	KafkaOffset    int64
}

type UserRegistration struct {
	UserID       uint64
	Email        string
	Name         string
	RegisteredAt time.Time
	KafkaProvenance
}

type OrderFunnelEvent struct {
	OrderID     uint64
	UserID      uint64
	EventType   string // created | paid | completed | cancelled
	TotalAmount string
	Reason      string
	OccurredAt  time.Time
	KafkaProvenance
}

type OrderLineItem struct {
	OrderID      uint64
	ProductID    uint64
	StoreID      uint64
	StoreName    string
	CategoryID   uint64
	CategoryName string
	CategorySlug string
	Quantity     uint32
	UnitPrice    string
	OccurredAt   time.Time
	KafkaProvenance
}

type ProductDim struct {
	ProductID    uint64
	StoreID      uint64
	StoreName    string
	CategoryID   uint64
	CategoryName string
	CategorySlug string
	KafkaProvenance
}

type SearchQuery struct {
	Query        string
	ResultsCount uint32
	Filters      string
	Sort         string
	OccurredAt   time.Time
	KafkaProvenance
}

type DayCount struct {
	Date       string `json:"date"`
	Count      uint64 `json:"count"`
	Cumulative uint64 `json:"cumulative,omitempty"`
}

type UsersMetrics struct {
	TotalUsers uint64     `json:"total_users"`
	Growth     []DayCount `json:"growth"`
}

type OrderFunnel struct {
	Created   uint64 `json:"created"`
	Paid      uint64 `json:"paid"`
	Cancelled uint64 `json:"cancelled"`
	Open      uint64 `json:"open"`
	Completed uint64 `json:"completed"`
}

type OrderConversions struct {
	CreatedToPaid      float64 `json:"created_to_paid"`
	CreatedToCancelled float64 `json:"created_to_cancelled"`
}

type RevenueDay struct {
	Date   string `json:"date"`
	Amount string `json:"amount"`
}

type RevenueMetrics struct {
	Total string       `json:"total"`
	ByDay []RevenueDay `json:"by_day"`
}

type OrdersMetrics struct {
	Funnel        OrderFunnel      `json:"funnel"`
	Conversions   OrderConversions `json:"conversions"`
	Revenue       RevenueMetrics   `json:"revenue"`
	AvgOrderValue string           `json:"avg_order_value"`
}

type QueryCount struct {
	Query string `json:"query"`
	Count uint64 `json:"count"`
}

type SearchMetrics struct {
	TotalSearches     uint64       `json:"total_searches"`
	ZeroResultCount   uint64       `json:"zero_result_count"`
	ZeroResultRate    float64      `json:"zero_result_rate"`
	PopularQueries    []QueryCount `json:"popular_queries"`
	ZeroResultQueries []QueryCount `json:"zero_result_queries"`
}

type DimensionOption struct {
	ID   uint64 `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug,omitempty"`
}

type DimensionBreakdown struct {
	ID             uint64 `json:"id"`
	Name           string `json:"name"`
	Slug           string `json:"slug,omitempty"`
	OrdersCount    uint64 `json:"orders_count"`
	ItemsQuantity  uint64 `json:"items_quantity"`
	LineAmount     string `json:"line_amount"`
	PaidLineAmount string `json:"paid_line_amount"`
}

type DimensionMetrics struct {
	ID             uint64               `json:"id,omitempty"`
	Name           string               `json:"name,omitempty"`
	Slug           string               `json:"slug,omitempty"`
	Funnel         *OrderFunnel         `json:"funnel,omitempty"`
	Conversions    *OrderConversions    `json:"conversions,omitempty"`
	OrdersCount    uint64               `json:"orders_count"`
	ItemsQuantity  uint64               `json:"items_quantity"`
	LineAmount     string               `json:"line_amount"`
	PaidLineAmount string               `json:"paid_line_amount"`
	Items          []DimensionBreakdown `json:"items,omitempty"`
	Options        []DimensionOption    `json:"options,omitempty"`
}
