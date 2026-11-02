package store

import (
	"testing"

	"github.com/shopspring/decimal"
	"github.com/stretchr/testify/assert"
)

func TestFormatDecimal(t *testing.T) {
	t.Parallel()

	assert.Equal(t, "0.00", formatDecimal(decimal.Zero))
	assert.Equal(t, "12.50", formatDecimal(decimal.RequireFromString("12.5")))
	assert.Equal(t, "12.35", formatDecimal(decimal.RequireFromString("12.345")))
	assert.Equal(t, "12.34", formatDecimal(decimal.RequireFromString("12.344")))
}

func TestDecodeAmount(t *testing.T) {
	t.Parallel()

	assert.True(t, DecodeAmount("12.50").Equal(decimal.RequireFromString("12.5")))
	assert.True(t, DecodeAmount("").IsZero())
	assert.True(t, DecodeAmount("not-a-number").IsZero())
	assert.Equal(t, "12.50", formatDecimal(DecodeAmount("12.50")))
}
