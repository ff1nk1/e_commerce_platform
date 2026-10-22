package store

import "fmt"

// Dimension describes a break-down axis for order line analytics (store / category).
type Dimension struct {
	IDCol   string
	NameCol string
	HasSlug bool
}

var (
	DimStore = Dimension{
		IDCol:   "store_id",
		NameCol: "store_name",
		HasSlug: false,
	}
	DimCategory = Dimension{
		IDCol:   "category_id",
		NameCol: "category_name",
		HasSlug: true,
	}
)

func (d Dimension) optionsSlugExpr() string {
	categorySlugAgg := "anyLast(nullIf(category_slug, ''))"
	emptySlugExpr := "cast('' AS String)"
	if d.HasSlug {
		return categorySlugAgg
	}
	return emptySlugExpr
}

func (d Dimension) nameExprs() (dimJoin, nameExpr, slugExpr string) {
	// Prefer non-empty name/slug from the line item; fall back to product_dim.
	categoryNameExpr := `ifNull(nullIf(anyLast(nullIf(li.category_name, '')), ''), ifNull(any(dim.dim_name), ''))`
	categorySlugExpr := `ifNull(nullIf(anyLast(nullIf(li.category_slug, '')), ''), ifNull(any(dim.dim_slug), ''))`
	storeNameExpr := `ifNull(nullIf(anyLast(nullIf(li.store_name, '')), ''), ifNull(any(dim.dim_name), ''))`
	emptySlugExpr := `cast('' AS String)`

	if d.HasSlug {
		categoryDimJoin := `
		LEFT JOIN (
			SELECT
				category_id AS join_id,
				anyLast(nullIf(category_name, '')) AS dim_name,
				anyLast(nullIf(category_slug, '')) AS dim_slug
			FROM product_dim FINAL
			WHERE category_id != 0
			GROUP BY category_id
		) AS dim ON dim.join_id = li.category_id`
		return categoryDimJoin, categoryNameExpr, categorySlugExpr
	}

	storeDimJoin := `
		LEFT JOIN (
			SELECT
				store_id AS join_id,
				anyLast(nullIf(store_name, '')) AS dim_name
			FROM product_dim FINAL
			WHERE store_id != 0
			GROUP BY store_id
		) AS dim ON dim.join_id = li.store_id`
	return storeDimJoin, storeNameExpr, emptySlugExpr
}

func displayDimName(name string, id uint64) string {
	if name == "" {
		return fmt.Sprintf("#%d", id)
	}
	return name
}
