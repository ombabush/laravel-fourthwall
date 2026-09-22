{{-- A cart in the site's header: an icon and a count.

     The count comes from the session, updated whenever the cart changes, so
     this costs no request on any page. Renders nothing where there is no cart
     (no storefront token) — or, unless `always`, while the cart is empty.

     <x-fourthwall::cart-icon />                 links to fourthwall.cart.page
     <x-fourthwall::cart-icon href="/shop/cart" always /> --}}
@props(['href' => null, 'always' => false, 'label' => null])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$cart = $fw->cart();
	$count = $cart?->count() ?? 0;
	$href ??= ($page = config('fourthwall.cart.page')) ? (\Illuminate\Support\Facades\Route::has($page) ? route($page) : url($page)) : null;
@endphp
@if ($cart && $href && ($count > 0 || $always))
	<a {{ $attributes->class(['fw-cart-icon', 'is-empty' => $count === 0]) }} href="{{ $href }}"
	   aria-label="{{ $label ?? trans_choice('fourthwall::shop.cart_n', $count, ['count' => $count]) }}">
		<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M5 7h14l-1.2 12.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>
		@if ($count > 0)<span class="fw-cart-icon__count">{{ $count }}</span>@endif
	</a>
@endif
