package store_test

import (
	"context"
	"testing"
	"time"

	"analytic_service/internal/store"
	"analytic_service/internal/testutil"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func ts(day string) time.Time {
	t, err := time.ParseInLocation("2006-01-02", day, time.UTC)
	if err != nil {
		panic(err)
	}
	return t
}

func ptrTime(t time.Time) *time.Time { return &t }

func TestUsersMetricsGrowthAndRange(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()

	require.NoError(t, st.InsertUserRegistration(ctx, store.UserRegistration{
		UserID: 1, Email: "a@x", Name: "A", RegisteredAt: ts("2024-01-01"),
	}))
	require.NoError(t, st.InsertUserRegistration(ctx, store.UserRegistration{
		UserID: 2, Email: "b@x", Name: "B", RegisteredAt: ts("2024-01-01").Add(time.Hour),
	}))
	require.NoError(t, st.InsertUserRegistration(ctx, store.UserRegistration{
		UserID: 3, Email: "c@x", Name: "C", RegisteredAt: ts("2024-01-02"),
	}))
	require.NoError(t, st.InsertUserRegistration(ctx, store.UserRegistration{
		UserID: 4, Email: "d@x", Name: "D", RegisteredAt: ts("2024-01-05"),
	}))

	from, to := ptrTime(ts("2024-01-01")), ptrTime(ts("2024-01-03"))
	m, err := st.UsersMetrics(ctx, from, to)
	require.NoError(t, err)
	assert.Equal(t, uint64(3), m.TotalUsers)
	require.Len(t, m.Growth, 2)
	assert.Equal(t, "2024-01-01", m.Growth[0].Date)
	assert.Equal(t, uint64(2), m.Growth[0].Count)
	assert.Equal(t, uint64(2), m.Growth[0].Cumulative)
	assert.Equal(t, "2024-01-02", m.Growth[1].Date)
	assert.Equal(t, uint64(1), m.Growth[1].Count)
	assert.Equal(t, uint64(3), m.Growth[1].Cumulative)
}

func TestOrdersMetricsFunnelExclusivityAndConversions(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()
	day := ts("2024-02-01")

	// order 1: created + paid + cancelled → cancelled wins over paid; completed independent
	for _, ev := range []store.OrderFunnelEvent{
		{OrderID: 1, UserID: 1, EventType: "created", TotalAmount: "100.00", OccurredAt: day},
		{OrderID: 1, UserID: 1, EventType: "paid", TotalAmount: "100.00", OccurredAt: day.Add(time.Hour)},
		{OrderID: 1, UserID: 1, EventType: "cancelled", TotalAmount: "100.00", OccurredAt: day.Add(2 * time.Hour)},
		{OrderID: 1, UserID: 1, EventType: "completed", TotalAmount: "100.00", OccurredAt: day.Add(3 * time.Hour)},
		// order 2: created + paid only
		{OrderID: 2, UserID: 2, EventType: "created", TotalAmount: "50.00", OccurredAt: day},
		{OrderID: 2, UserID: 2, EventType: "paid", TotalAmount: "50.00", OccurredAt: day.Add(time.Hour)},
		// order 3: created only → open
		{OrderID: 3, UserID: 3, EventType: "created", TotalAmount: "20.00", OccurredAt: day},
	} {
		require.NoError(t, st.InsertOrderFunnelEvent(ctx, ev))
	}

	from, to := ptrTime(day), ptrTime(day.Add(24*time.Hour))
	m, err := st.OrdersMetrics(ctx, from, to)
	require.NoError(t, err)

	assert.Equal(t, uint64(3), m.Funnel.Created)
	assert.Equal(t, uint64(1), m.Funnel.Cancelled)
	assert.Equal(t, uint64(1), m.Funnel.Paid)
	assert.Equal(t, uint64(1), m.Funnel.Open)
	assert.Equal(t, uint64(1), m.Funnel.Completed)
	assert.InDelta(t, 1.0/3.0, m.Conversions.CreatedToPaid, 1e-9)
	assert.InDelta(t, 1.0/3.0, m.Conversions.CreatedToCancelled, 1e-9)
}

func TestOrdersMetricsRevenueUsesPaidWindowNotCohort(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()

	// created outside window, paid inside window → revenue counts, funnel created does not
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 10, UserID: 1, EventType: "created", TotalAmount: "80.00", OccurredAt: ts("2024-03-01"),
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 10, UserID: 1, EventType: "paid", TotalAmount: "80.00", OccurredAt: ts("2024-03-10"),
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 11, UserID: 2, EventType: "created", TotalAmount: "20.00", OccurredAt: ts("2024-03-10"),
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 11, UserID: 2, EventType: "paid", TotalAmount: "20.00", OccurredAt: ts("2024-03-10").Add(time.Hour),
	}))

	from, to := ptrTime(ts("2024-03-10")), ptrTime(ts("2024-03-11"))
	m, err := st.OrdersMetrics(ctx, from, to)
	require.NoError(t, err)

	assert.Equal(t, uint64(1), m.Funnel.Created) // only order 11 created in window
	assert.Equal(t, "100.00", m.Revenue.Total)   // both paid events in window
	assert.Equal(t, "50.00", m.AvgOrderValue)
	require.NotEmpty(t, m.Revenue.ByDay)
	assert.Equal(t, "2024-03-10", m.Revenue.ByDay[0].Date)
	assert.Equal(t, "100.00", m.Revenue.ByDay[0].Amount)
}

func TestSearchMetricsZeroRateAndEmptyLabel(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()
	day := ts("2024-04-01")

	for _, q := range []store.SearchQuery{
		{Query: "boots", ResultsCount: 5, Filters: "{}", OccurredAt: day, KafkaProvenance: store.KafkaProvenance{KafkaTopic: "t", KafkaOffset: 1}},
		{Query: "boots", ResultsCount: 0, Filters: "{}", OccurredAt: day, KafkaProvenance: store.KafkaProvenance{KafkaTopic: "t", KafkaOffset: 2}},
		{Query: "", ResultsCount: 0, Filters: "{}", OccurredAt: day, KafkaProvenance: store.KafkaProvenance{KafkaTopic: "t", KafkaOffset: 3}},
		{Query: "hat", ResultsCount: 2, Filters: "{}", OccurredAt: day, KafkaProvenance: store.KafkaProvenance{KafkaTopic: "t", KafkaOffset: 4}},
	} {
		require.NoError(t, st.InsertSearchQuery(ctx, q))
	}

	m, err := st.SearchMetrics(ctx, nil, nil, 10)
	require.NoError(t, err)
	assert.Equal(t, uint64(4), m.TotalSearches)
	assert.Equal(t, uint64(2), m.ZeroResultCount)
	assert.InDelta(t, 0.5, m.ZeroResultRate, 1e-9)

	require.NotEmpty(t, m.PopularQueries)
	assert.Equal(t, "boots", m.PopularQueries[0].Query)
	assert.Equal(t, uint64(2), m.PopularQueries[0].Count)

	foundEmpty := false
	for _, z := range m.ZeroResultQueries {
		if z.Query == "(empty)" {
			foundEmpty = true
			assert.Equal(t, uint64(1), z.Count)
		}
	}
	assert.True(t, foundEmpty)
}

func TestProductDimLookup(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()

	require.NoError(t, st.InsertProductDim(ctx, store.ProductDim{
		ProductID: 42, StoreID: 7, StoreName: "Shop", CategoryID: 3,
		CategoryName: "Cat", CategorySlug: "cat",
		KafkaProvenance: store.KafkaProvenance{KafkaTopic: "p", KafkaOffset: 1},
	}))

	dim, found, err := st.LookupProductDim(ctx, 42)
	require.NoError(t, err)
	assert.True(t, found)
	assert.Equal(t, "Shop", dim.StoreName)
	assert.Equal(t, "cat", dim.CategorySlug)

	_, found, err = st.LookupProductDim(ctx, 999)
	require.NoError(t, err)
	assert.False(t, found)
}

func TestOrdersByStoreRankingAndDetail(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()
	day := ts("2024-05-01")

	require.NoError(t, st.InsertProductDim(ctx, store.ProductDim{
		ProductID: 1, StoreID: 10, StoreName: "Alpha", CategoryID: 100,
		CategoryName: "Gear", CategorySlug: "gear",
	}))
	require.NoError(t, st.InsertProductDim(ctx, store.ProductDim{
		ProductID: 2, StoreID: 20, StoreName: "Beta", CategoryID: 100,
		CategoryName: "Gear", CategorySlug: "gear",
	}))

	// order 1 paid at store 10
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 1, UserID: 1, EventType: "created", TotalAmount: "30.00", OccurredAt: day,
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 1, UserID: 1, EventType: "paid", TotalAmount: "30.00", OccurredAt: day.Add(time.Hour),
	}))
	require.NoError(t, st.InsertOrderLineItems(ctx, []store.OrderLineItem{{
		OrderID: 1, ProductID: 1, StoreID: 10, StoreName: "Alpha",
		CategoryID: 100, CategoryName: "Gear", CategorySlug: "gear",
		Quantity: 2, UnitPrice: "15.00", OccurredAt: day,
	}}))

	// order 2 open at store 20
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 2, UserID: 2, EventType: "created", TotalAmount: "10.00", OccurredAt: day,
	}))
	require.NoError(t, st.InsertOrderLineItems(ctx, []store.OrderLineItem{{
		OrderID: 2, ProductID: 2, StoreID: 20, StoreName: "Beta",
		CategoryID: 100, CategoryName: "Gear", CategorySlug: "gear",
		Quantity: 1, UnitPrice: "10.00", OccurredAt: day,
	}}))

	from, to := ptrTime(day), ptrTime(day.Add(24*time.Hour))
	rank, err := st.OrdersByStore(ctx, nil, from, to, 10)
	require.NoError(t, err)
	require.Len(t, rank.Items, 2)
	assert.NotEmpty(t, rank.Options)

	// Alpha has paid line amount, should rank first by orders_count then paid
	assert.Equal(t, uint64(10), rank.Items[0].ID)
	assert.Equal(t, "Alpha", rank.Items[0].Name)
	assert.Equal(t, uint64(1), rank.Items[0].OrdersCount)
	assert.Equal(t, "30.00", rank.Items[0].PaidLineAmount)

	storeID := uint64(10)
	detail, err := st.OrdersByStore(ctx, &storeID, from, to, 10)
	require.NoError(t, err)
	assert.Equal(t, uint64(10), detail.ID)
	assert.Equal(t, "Alpha", detail.Name)
	assert.Equal(t, uint64(1), detail.OrdersCount)
	assert.Equal(t, "30.00", detail.PaidLineAmount)
	require.NotNil(t, detail.Funnel)
	assert.Equal(t, uint64(1), detail.Funnel.Created)
	assert.Equal(t, uint64(1), detail.Funnel.Paid)
	assert.Equal(t, uint64(0), detail.Funnel.Open)
}

func TestOrdersByCategoryDetail(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()
	day := ts("2024-06-01")

	require.NoError(t, st.InsertProductDim(ctx, store.ProductDim{
		ProductID: 5, StoreID: 1, StoreName: "S", CategoryID: 77,
		CategoryName: "Outdoor", CategorySlug: "outdoor",
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 50, UserID: 1, EventType: "created", TotalAmount: "40.00", OccurredAt: day,
	}))
	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 50, UserID: 1, EventType: "cancelled", TotalAmount: "40.00", OccurredAt: day.Add(time.Hour),
	}))
	require.NoError(t, st.InsertOrderLineItems(ctx, []store.OrderLineItem{{
		OrderID: 50, ProductID: 5, StoreID: 1, StoreName: "S",
		CategoryID: 77, CategoryName: "Outdoor", CategorySlug: "outdoor",
		Quantity: 1, UnitPrice: "40.00", OccurredAt: day,
	}}))

	catID := uint64(77)
	from, to := ptrTime(day), ptrTime(day.Add(24*time.Hour))
	m, err := st.OrdersByCategory(ctx, &catID, from, to, 10)
	require.NoError(t, err)
	assert.Equal(t, "Outdoor", m.Name)
	assert.Equal(t, "outdoor", m.Slug)
	require.NotNil(t, m.Funnel)
	assert.Equal(t, uint64(1), m.Funnel.Created)
	assert.Equal(t, uint64(1), m.Funnel.Cancelled)
	assert.Equal(t, uint64(0), m.Funnel.Paid)
	require.NotNil(t, m.Conversions)
	assert.InDelta(t, 1.0, m.Conversions.CreatedToCancelled, 1e-9)
}

func TestOrdersByStoreNameFallbackToHashID(t *testing.T) {
	st, conn := testutil.OpenTestStore(t)
	testutil.TruncateAnalytics(t, conn)
	ctx := context.Background()
	day := ts("2024-07-01")

	require.NoError(t, st.InsertOrderFunnelEvent(ctx, store.OrderFunnelEvent{
		OrderID: 90, UserID: 1, EventType: "created", TotalAmount: "5.00", OccurredAt: day,
	}))
	require.NoError(t, st.InsertOrderLineItems(ctx, []store.OrderLineItem{{
		OrderID: 90, ProductID: 9, StoreID: 333, StoreName: "",
		CategoryID: 1, CategoryName: "X", CategorySlug: "x",
		Quantity: 1, UnitPrice: "5.00", OccurredAt: day,
	}}))

	from, to := ptrTime(day), ptrTime(day.Add(24*time.Hour))
	m, err := st.OrdersByStore(ctx, nil, from, to, 10)
	require.NoError(t, err)
	require.Len(t, m.Items, 1)
	assert.Equal(t, "#333", m.Items[0].Name)
}
