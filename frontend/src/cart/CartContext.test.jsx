import { describe, expect, it, beforeEach } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { CartProvider, useCart } from "./CartContext";

function wrapper({ children }) {
  return <CartProvider>{children}</CartProvider>;
}

describe("CartContext cents total", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it("starts empty", () => {
    const { result } = renderHook(() => useCart(), { wrapper });
    expect(result.current.items).toEqual([]);
    expect(result.current.totalCents).toBe(0);
    expect(result.current.totalCount).toBe(0);
  });

  it("accumulates a precise cents total", () => {
    const { result } = renderHook(() => useCart(), { wrapper });

    act(() => {
      result.current.addItem({ id: 1, name: "A", price: "0.10" }, 1);
      result.current.addItem({ id: 2, name: "B", price: "0.20" }, 1);
    });

    expect(result.current.totalCents).toBe(30);
    expect(result.current.totalPrice).toBeCloseTo(0.3, 10);
    expect(result.current.totalCount).toBe(2);
  });

  it("sums line totals across quantities", () => {
    const { result } = renderHook(() => useCart(), { wrapper });

    act(() => {
      result.current.addItem({ id: 1, name: "A", price: "19.99" }, 3);
      result.current.addItem({ id: 2, name: "B", price: "5.50" }, 2);
    });

    // 19.99*3 = 5997 ; 5.50*2 = 1100 ; total 7097
    expect(result.current.totalCents).toBe(7097);
    expect(result.current.totalCount).toBe(5);
  });

  it("adds quantities for the same product", () => {
    const { result } = renderHook(() => useCart(), { wrapper });

    act(() => {
      result.current.addItem({ id: 1, name: "A", price: "10" }, 1);
      result.current.addItem({ id: 1, name: "A", price: "10" }, 2);
    });

    expect(result.current.items).toHaveLength(1);
    expect(result.current.totalCents).toBe(3000);
    expect(result.current.totalCount).toBe(3);
  });
});
