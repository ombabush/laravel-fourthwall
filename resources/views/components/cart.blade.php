{{-- The cart itself, for the host's cart page: lines, quantities, subtotal
     and «checkout». Every control is a plain form posting to the package's
     cart routes, so it works without JavaScript.

     Route::view('/shop/cart', 'shop.cart')->name('shop.cart');
     FOURTHWALL_CART_PATH=shop/cart  FOURTHWALL_CART_PAGE=shop.cart
     …and in the view: <x-fourthwall::cart /> --}}
@props(['params' => []])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$cart = $fw->cart();
	try { $data = $cart?->get(); } catch (\Throwable $e) { $data = null; $failed = true; }
	$flash = session('fourthwall.cart');
@endphp
<section {{ $attributes->class('fw-cart') }}>
	@if (! empty($flash['error']) || ! empty($failed))
		<p class="fw-cart__error" role="alert">{{ $flash['error'] ?? __('fourthwall::shop.cart_failed') }}</p>
	@endif

	@if (! $cart)
		<p class="fw-cart__empty">{{ __('fourthwall::shop.cart_unavailable') }}</p>
	@elseif (! $data || $data->isEmpty())
		<p class="fw-cart__empty">{{ __('fourthwall::shop.cart_empty') }}</p>
	@else
		<ul class="fw-cart__lines">
			@foreach ($data->lines as $line)
				<li class="fw-cart__line">
					@if ($line->image)
						<img class="fw-cart__img" src="{{ $line->image->url }}" alt="" width="72" height="72" loading="lazy">
					@endif
					<div class="fw-cart__what">
						<span class="fw-cart__name">{{ $line->productName ?? $line->variantName }}</span>
						@if ($line->productName)<span class="fw-cart__variant">{{ $line->variantName }}</span>@endif
						<span class="fw-cart__unit">{{ $line->unitPrice->format() }}</span>
					</div>
					<form class="fw-cart__qty" method="post" action="{{ route('fourthwall.cart.change') }}">
						@csrf
						<input type="hidden" name="variant" value="{{ $line->variantId }}">
						<input type="number" name="quantity" value="{{ $line->quantity }}" min="0" max="1000" inputmode="numeric"
						       aria-label="{{ __('fourthwall::shop.quantity') }}" onchange="this.form.requestSubmit()">
						<noscript><button type="submit">{{ __('fourthwall::shop.update') }}</button></noscript>
					</form>
					<span class="fw-cart__total">{{ $line->total()->format() }}</span>
					<form method="post" action="{{ route('fourthwall.cart.remove') }}">
						@csrf
						<input type="hidden" name="variant" value="{{ $line->variantId }}">
						<button class="fw-cart__remove" type="submit" aria-label="{{ __('fourthwall::shop.remove') }}">×</button>
					</form>
				</li>
			@endforeach
		</ul>
		<p class="fw-cart__subtotal">
			<span>{{ __('fourthwall::shop.subtotal') }}</span>
			<strong>{{ $data->subtotal()?->format(exact: true) }}</strong>
		</p>
		<p class="fw-cart__note">{{ __('fourthwall::shop.cart_note') }}</p>
		<a class="fw-buy fw-cart__checkout" href="{{ route('fourthwall.cart.checkout') }}">{{ __('fourthwall::shop.checkout') }}</a>
	@endif
</section>
