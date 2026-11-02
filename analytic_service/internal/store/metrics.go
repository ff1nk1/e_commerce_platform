package store

import (
	"context"
	"fmt"
	"time"

	"github.com/shopspring/decimal"
)

// funnelForCohort aggregates funnel outcomes for orders matching cohortWhere
// (filter on order_funnel_events for selecting order_ids, typically including event_type = 'created').
// Semantics: cancelled wins over paid; open = neither paid nor cancelled; completed is independent.
func (s *Store) funnelForCohort(ctx context.Context, cohortWhere string, args []any) (OrderFunnel, OrderConversions, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	funnelQ := fmt.Sprintf(`
		SELECT
			count() AS created,
			countIf(has_cancelled) AS cancelled,
			countIf(has_paid AND NOT has_cancelled) AS paid,
			countIf(NOT has_paid AND NOT has_cancelled) AS open,
			countIf(has_completed) AS completed
		FROM (
			SELECT
				order_id,
				max(event_type = 'paid') AS has_paid,
				max(event_type = 'cancelled') AS has_cancelled,
				max(event_type = 'completed') AS has_completed
			FROM order_funnel_events FINAL
			WHERE order_id IN (
				SELECT order_id
				FROM order_funnel_events FINAL
				WHERE %s
			)
			GROUP BY order_id
		)
	`, cohortWhere)

	var funnel OrderFunnel
	if err := s.conn.QueryRow(ctx, funnelQ, args...).Scan(
		&funnel.Created,
		&funnel.Cancelled,
		&funnel.Paid,
		&funnel.Open,
		&funnel.Completed,
	); err != nil {
		return funnel, OrderConversions{}, fmt.Errorf("funnel: %w", err)
	}
	return funnel, conversionsFromFunnel(funnel), nil
}

func (s *Store) UsersMetrics(ctx context.Context, from, to *time.Time) (UsersMetrics, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	var out UsersMetrics

	totalQ := `SELECT count() FROM user_registrations FINAL WHERE 1=1`
	args := []any{}
	totalQ, args = appendTimeRange(totalQ, args, "registered_at", from, to)
	if err := s.conn.QueryRow(ctx, totalQ, args...).Scan(&out.TotalUsers); err != nil {
		return out, fmt.Errorf("total users: %w", err)
	}

	growthQ := `
		SELECT toDate(registered_at) AS d, count() AS c
		FROM user_registrations FINAL
		WHERE 1=1
	`
	growthArgs := []any{}
	growthQ, growthArgs = appendTimeRange(growthQ, growthArgs, "registered_at", from, to)
	growthQ += ` GROUP BY d ORDER BY d`

	rows, err := s.conn.Query(ctx, growthQ, growthArgs...)
	if err != nil {
		return out, fmt.Errorf("growth query: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var cumulative uint64
	out.Growth = make([]DayCount, 0)
	for rows.Next() {
		var d time.Time
		var c uint64
		if err := rows.Scan(&d, &c); err != nil {
			return out, fmt.Errorf("growth scan: %w", err)
		}
		cumulative += c
		out.Growth = append(out.Growth, DayCount{
			Date:       d.Format("2006-01-02"),
			Count:      c,
			Cumulative: cumulative,
		})
	}
	return out, rows.Err()
}

func (s *Store) OrdersMetrics(ctx context.Context, from, to *time.Time) (OrdersMetrics, error) {
	var out OrdersMetrics

	cohortFilter := `event_type = 'created'`
	args := []any{}
	cohortFilter, args = appendTimeRange(cohortFilter, args, "occurred_at", from, to)

	funnel, conv, err := s.funnelForCohort(ctx, cohortFilter, args)
	if err != nil {
		return out, err
	}
	out.Funnel = funnel
	out.Conversions = conv

	revQ := `
		SELECT
			ifNull(sum(total_amount), 0) AS total,
			ifNull(avg(total_amount), 0) AS aov
		FROM order_funnel_events FINAL
		WHERE event_type = 'paid'
	`
	revArgs := []any{}
	revQ, revArgs = appendTimeRange(revQ, revArgs, "occurred_at", from, to)

	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	var total, aov decimal.Decimal
	if err := s.conn.QueryRow(ctx, revQ, revArgs...).Scan(&total, &aov); err != nil {
		return out, fmt.Errorf("revenue: %w", err)
	}
	out.Revenue.Total = formatDecimal(total)
	out.AvgOrderValue = formatDecimal(aov)

	byDayQ := `
		SELECT toDate(occurred_at) AS d, sum(total_amount) AS amount
		FROM order_funnel_events FINAL
		WHERE event_type = 'paid'
	`
	byDayArgs := []any{}
	byDayQ, byDayArgs = appendTimeRange(byDayQ, byDayArgs, "occurred_at", from, to)
	byDayQ += ` GROUP BY d ORDER BY d`

	rows, err := s.conn.Query(ctx, byDayQ, byDayArgs...)
	if err != nil {
		return out, fmt.Errorf("revenue by day: %w", err)
	}
	defer func() { _ = rows.Close() }()

	out.Revenue.ByDay = make([]RevenueDay, 0)
	for rows.Next() {
		var d time.Time
		var amount decimal.Decimal
		if err := rows.Scan(&d, &amount); err != nil {
			return out, fmt.Errorf("revenue by day scan: %w", err)
		}
		out.Revenue.ByDay = append(out.Revenue.ByDay, RevenueDay{
			Date:   d.Format("2006-01-02"),
			Amount: formatDecimal(amount),
		})
	}
	return out, rows.Err()
}

func (s *Store) SearchMetrics(ctx context.Context, from, to *time.Time, limit int) (SearchMetrics, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	var out SearchMetrics
	if limit <= 0 {
		limit = 20
	}

	statsQ := `
		SELECT
			count() AS total,
			countIf(results_count = 0) AS zero_cnt
		FROM search_queries FINAL
		WHERE 1=1
	`
	args := []any{}
	statsQ, args = appendTimeRange(statsQ, args, "occurred_at", from, to)
	if err := s.conn.QueryRow(ctx, statsQ, args...).Scan(&out.TotalSearches, &out.ZeroResultCount); err != nil {
		return out, fmt.Errorf("search stats: %w", err)
	}
	if out.TotalSearches > 0 {
		out.ZeroResultRate = float64(out.ZeroResultCount) / float64(out.TotalSearches)
	}

	popular, err := s.topQueries(ctx, from, to, false, limit)
	if err != nil {
		return out, err
	}
	out.PopularQueries = popular

	zero, err := s.topQueries(ctx, from, to, true, limit)
	if err != nil {
		return out, err
	}
	out.ZeroResultQueries = zero

	return out, nil
}

func (s *Store) OrdersByStore(ctx context.Context, storeID *uint64, from, to *time.Time, limit int) (DimensionMetrics, error) {
	return s.ordersByDimension(ctx, DimStore, storeID, from, to, limit)
}

func (s *Store) OrdersByCategory(ctx context.Context, categoryID *uint64, from, to *time.Time, limit int) (DimensionMetrics, error) {
	return s.ordersByDimension(ctx, DimCategory, categoryID, from, to, limit)
}

func (s *Store) ListStoreOptions(ctx context.Context) ([]DimensionOption, error) {
	return s.listDimensionOptions(ctx, DimStore)
}

func (s *Store) ListCategoryOptions(ctx context.Context) ([]DimensionOption, error) {
	return s.listDimensionOptions(ctx, DimCategory)
}

func (s *Store) listDimensionOptions(ctx context.Context, dim Dimension) ([]DimensionOption, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	q := fmt.Sprintf(`
		SELECT
			%s AS dim_id,
			ifNull(anyLast(nullIf(%s, '')), '') AS dim_name,
			%s AS dim_slug
		FROM product_dim FINAL
		WHERE %s != 0
		GROUP BY dim_id
		HAVING dim_name != ''
		ORDER BY dim_name ASC
	`, dim.IDCol, dim.NameCol, dim.optionsSlugExpr(), dim.IDCol)

	rows, err := s.conn.Query(ctx, q)
	if err != nil {
		return nil, fmt.Errorf("%s options: %w", dim.IDCol, err)
	}
	defer func() { _ = rows.Close() }()

	out := make([]DimensionOption, 0)
	for rows.Next() {
		var opt DimensionOption
		if err := rows.Scan(&opt.ID, &opt.Name, &opt.Slug); err != nil {
			return nil, fmt.Errorf("%s options scan: %w", dim.IDCol, err)
		}
		out = append(out, opt)
	}
	return out, rows.Err()
}

func (s *Store) ordersByDimension(ctx context.Context, dim Dimension, dimID *uint64, from, to *time.Time, limit int) (DimensionMetrics, error) {
	var out DimensionMetrics
	if limit <= 0 {
		limit = 20
	}

	options, optErr := s.listDimensionOptions(ctx, dim)
	if optErr != nil {
		return out, optErr
	}

	if dimID != nil {
		detail, err := s.dimensionDetail(ctx, dim, *dimID, from, to)
		if err != nil {
			return out, err
		}
		detail.Options = options
		return detail, nil
	}

	items, err := s.dimensionRanking(ctx, dim, from, to, limit)
	if err != nil {
		return out, err
	}
	out.Items = items
	out.Options = options
	out.LineAmount = "0.00"
	out.PaidLineAmount = "0.00"
	return out, nil
}

func (s *Store) dimensionRanking(ctx context.Context, dim Dimension, from, to *time.Time, limit int) ([]DimensionBreakdown, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	dimJoin, nameExpr, slugExpr := dim.nameExprs()

	q := fmt.Sprintf(`
		SELECT
			li.%s AS dim_id,
			%s AS dim_name,
			%s AS dim_slug,
			uniqExact(li.order_id) AS orders_count,
			toUInt64(sum(li.quantity)) AS items_quantity,
			ifNull(sum(li.quantity * li.unit_price), 0) AS line_amount,
			ifNull(sumIf(li.quantity * li.unit_price, paid.order_id != 0), 0) AS paid_line_amount
		FROM order_line_items AS li FINAL
		LEFT JOIN (
			SELECT order_id
			FROM order_funnel_events FINAL
			WHERE event_type = 'paid'
		) AS paid ON paid.order_id = li.order_id
		%s
		WHERE li.%s != 0
	`, dim.IDCol, nameExpr, slugExpr, dimJoin, dim.IDCol)
	args := []any{}
	q, args = appendTimeRange(q, args, "li.occurred_at", from, to)
	q += ` GROUP BY dim_id ORDER BY orders_count DESC, paid_line_amount DESC LIMIT ?`
	args = append(args, limit)

	rows, err := s.conn.Query(ctx, q, args...)
	if err != nil {
		return nil, fmt.Errorf("%s ranking: %w", dim.IDCol, err)
	}
	defer func() { _ = rows.Close() }()

	out := make([]DimensionBreakdown, 0)
	for rows.Next() {
		var item DimensionBreakdown
		var lineAmt, paidAmt decimal.Decimal
		if err := rows.Scan(&item.ID, &item.Name, &item.Slug, &item.OrdersCount, &item.ItemsQuantity, &lineAmt, &paidAmt); err != nil {
			return nil, fmt.Errorf("%s ranking scan: %w", dim.IDCol, err)
		}
		item.Name = displayDimName(item.Name, item.ID)
		item.LineAmount = formatDecimal(lineAmt)
		item.PaidLineAmount = formatDecimal(paidAmt)
		out = append(out, item)
	}
	return out, rows.Err()
}

func (s *Store) dimensionDetail(ctx context.Context, dim Dimension, dimID uint64, from, to *time.Time) (DimensionMetrics, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	var out DimensionMetrics
	out.ID = dimID
	out.LineAmount = "0.00"
	out.PaidLineAmount = "0.00"

	dimJoin, nameExpr, slugExpr := dim.nameExprs()

	lineQ := fmt.Sprintf(`
		SELECT
			uniqExact(li.order_id) AS orders_count,
			toUInt64(ifNull(sum(li.quantity), 0)) AS items_quantity,
			ifNull(sum(li.quantity * li.unit_price), 0) AS line_amount,
			ifNull(sumIf(li.quantity * li.unit_price, paid.order_id != 0), 0) AS paid_line_amount,
			%s AS dim_name,
			%s AS dim_slug
		FROM order_line_items AS li FINAL
		LEFT JOIN (
			SELECT order_id
			FROM order_funnel_events FINAL
			WHERE event_type = 'paid'
		) AS paid ON paid.order_id = li.order_id
		%s
		WHERE li.%s = ?
	`, nameExpr, slugExpr, dimJoin, dim.IDCol)
	lineArgs := []any{dimID}
	lineQ, lineArgs = appendTimeRange(lineQ, lineArgs, "li.occurred_at", from, to)

	var lineAmt, paidAmt decimal.Decimal
	if err := s.conn.QueryRow(ctx, lineQ, lineArgs...).Scan(
		&out.OrdersCount,
		&out.ItemsQuantity,
		&lineAmt,
		&paidAmt,
		&out.Name,
		&out.Slug,
	); err != nil {
		return out, fmt.Errorf("%s detail lines: %w", dim.IDCol, err)
	}
	out.LineAmount = formatDecimal(lineAmt)
	out.PaidLineAmount = formatDecimal(paidAmt)
	out.Name = displayDimName(out.Name, dimID)

	cohortFilter := fmt.Sprintf(`
		order_id IN (
			SELECT DISTINCT order_id
			FROM order_line_items FINAL
			WHERE %s = ?
		)
		AND event_type = 'created'
	`, dim.IDCol)
	args := []any{dimID}
	cohortFilter, args = appendTimeRange(cohortFilter, args, "occurred_at", from, to)

	funnel, conv, err := s.funnelForCohort(ctx, cohortFilter, args)
	if err != nil {
		return out, fmt.Errorf("%s detail funnel: %w", dim.IDCol, err)
	}
	out.Funnel = &funnel
	out.Conversions = &conv

	return out, nil
}

func (s *Store) topQueries(ctx context.Context, from, to *time.Time, zeroOnly bool, limit int) ([]QueryCount, error) {
	ctx, cancel := s.withQuery(ctx)
	defer cancel()

	q := `
		SELECT if(query = '', '(empty)', query) AS q, count() AS c
		FROM search_queries FINAL
		WHERE 1=1
	`
	args := []any{}
	if zeroOnly {
		q += ` AND results_count = 0`
	}
	q, args = appendTimeRange(q, args, "occurred_at", from, to)
	q += ` GROUP BY q ORDER BY c DESC LIMIT ?`
	args = append(args, limit)

	rows, err := s.conn.Query(ctx, q, args...)
	if err != nil {
		return nil, fmt.Errorf("top queries: %w", err)
	}
	defer func() { _ = rows.Close() }()

	out := make([]QueryCount, 0)
	for rows.Next() {
		var item QueryCount
		if err := rows.Scan(&item.Query, &item.Count); err != nil {
			return nil, fmt.Errorf("top queries scan: %w", err)
		}
		out = append(out, item)
	}
	return out, rows.Err()
}

func formatDecimal(d decimal.Decimal) string {
	return d.Round(2).StringFixed(2)
}
