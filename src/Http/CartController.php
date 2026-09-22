<?php

namespace Ombabush\Fourthwall\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ombabush\Fourthwall\Exceptions\FourthwallException;
use Ombabush\Fourthwall\Fourthwall;

/**
 * The cart's endpoints, mounted only when `fourthwall.cart.path` is set.
 *
 * Plain forms first: every action is a POST that redirects back, so the cart
 * works with JavaScript off. A request that asks for JSON gets the cart back
 * as JSON instead, for a drawer that updates in place.
 *
 *   POST {path}/add       variant, quantity?, bundle?
 *   POST {path}/change    variant, quantity
 *   POST {path}/remove    variant
 *   GET  {path}           the cart, as JSON — the page is the host's own view
 *   GET  {path}/checkout  303 into Fourthwall's checkout with this cart
 */
class CartController
{
    public function show(Fourthwall $fw): JsonResponse
    {
        return response()->json($this->cart($fw)->get());
    }

    public function add(Request $request, Fourthwall $fw): JsonResponse|RedirectResponse
    {
        $v = $request->validate([
            'variant' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'bundle' => ['nullable', 'string', 'max:64'],
        ]);

        // Where the click came from, as cart metadata: it comes back on the
        // order, so the host can tell which page sold the thing.
        $metadata = array_filter([
            'source_page' => parse_url((string) $request->headers->get('referer'), PHP_URL_PATH),
        ]);

        return $this->respond($request, fn () => $this->cart($fw)->add($v['variant'], (int) ($v['quantity'] ?? 1), $v['bundle'] ?? null, $metadata), 'added');
    }

    public function change(Request $request, Fourthwall $fw): JsonResponse|RedirectResponse
    {
        $v = $request->validate([
            'variant' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        return $this->respond($request, fn () => $this->cart($fw)->change($v['variant'], (int) $v['quantity']), 'changed');
    }

    public function remove(Request $request, Fourthwall $fw): JsonResponse|RedirectResponse
    {
        $v = $request->validate(['variant' => ['required', 'string', 'max:64']]);

        return $this->respond($request, fn () => $this->cart($fw)->remove($v['variant']), 'removed');
    }

    public function checkout(Request $request, Fourthwall $fw): RedirectResponse
    {
        $url = $this->cart($fw)->checkoutUrl($fw->linkParams(), $request->query('coupon'));

        return $url ? redirect()->away($url, 303) : redirect()->back();
    }

    private function respond(Request $request, callable $action, string $what): JsonResponse|RedirectResponse
    {
        try {
            $cart = $action();
        } catch (FourthwallException $e) {
            Log::warning('[fourthwall] cart: '.$e->getMessage());

            return $request->expectsJson()
                ? response()->json(['error' => __('fourthwall::shop.cart_failed')], 502)
                : redirect()->back()->with('fourthwall.cart', ['error' => __('fourthwall::shop.cart_failed')]);
        }

        return $request->expectsJson()
            ? response()->json($cart)
            : redirect()->back()->with('fourthwall.cart', ['status' => $what, 'count' => $cart->count()]);
    }

    private function cart(Fourthwall $fw): \Ombabush\Fourthwall\Cart
    {
        return $fw->cart() ?? abort(404);
    }
}
