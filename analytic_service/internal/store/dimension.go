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
	if d.HasSlug {
		return "anyLast(nullIf(category_slug, ''))"
	}
	return "cast('' AS String)"
}

func (d Dimension) nameExprs() (dimJoin, nameExpr, slugExpr string) {
	if d.HasSlug {
		dimJoin = `
		LEFT JOIN (
			SELECT
				category_id AS join_id,
				anyLast(nullIf(category_name, '')) AS dim_name,
				anyLast(nullIf(category_slug, '')) AS dim_slug
			FROM product_dim FINAL
			WHERE category_id != 0
			GROUP BY category_id
		) AS dim ON dim.join_id = li.category_id`
		nameExpr = `ifNull(nullIf(anyLast(nullIf(li.category_name, '')), ''), ifNull(any(dim.dim_name), ''))`
		slugExpr = `ifNull(nullIf(anyLast(nullIf(li.category_slug, '')), ''), ifNull(any(dim.dim_slug), ''))`
		return dimJoin, nameExpr, slugExpr
	}

	dimJoin = `
		LEFT JOIN (
			SELECT
				store_id AS join_id,
				anyLast(nullIf(store_name, '')) AS dim_name
			FROM product_dim FINAL
			WHERE store_id != 0
			GROUP BY store_id
		) AS dim ON dim.join_id = li.store_id`
	nameExpr = `ifNull(nullIf(anyLast(nullIf(li.store_name, '')), ''), ifNull(any(dim.dim_name), ''))`
	slugExpr = `cast('' AS String)`
	return dimJoin, nameExpr, slugExpr
}

func displayDimName(name string, id uint64) string {
	if name == "" {
		return fmt.Sprintf("#%d", id)
	}
	return name
}
