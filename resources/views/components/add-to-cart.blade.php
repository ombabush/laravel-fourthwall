{{-- «Add to cart» where the site has a cart, «buy» where it does not.

     With a storefront token and `fourthwall.cart.path` set, this POSTs to the
     cart and comes back to the page. Without them it is the same plain GET
     into checkout that <x-fourthwall::product> uses — so a template can use
     this everywhere and let the configuration decide.

     <x-fourthwall::add-to-cart :product="$p" />
     <x-fourthwall::add-to-cart :product="$p" :quantity="false" /> --}}
@props(['product', 'params' => [], 'quantity' => true])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$carts = $fw->supports(\Ombabush\Fourthwall\Capability::Carts) && \Illuminate\Support\Facades\Route::has('fourthwall.cart.add');
	$buyable = $product->variants()->where('available', true);
	$single = $product->variants()->count() === 1;
@endphp
@if ($product->isBundle() || $product->variants()->isEmpty())
	<a {{ $attributes->class('fw-buy') }} href="{{ $fw->productLink($product, $params) }}" target="_blank" rel="noopener">{{ __('fourthwall::shop.view_in_shop') }}</a>
@elseif ($buyable->isEmpty())
	<p {{ $attributes->class('fw-soldout') }}>{{ __('fourthwall::shop.sold_out') }}</p>
@elseif ($carts)
	<form {{ $attributes->class('fw-add') }} method="post" action="{{ route('fourthwall.cart.add') }}">
		@csrf
		@if ($single)
			<input type="hidden" name="variant" value="{{ $product->variants[0]->id }}">
		@else
			<label class="fw-add__variant">
				<span>{{ __('fourthwall::shop.choose') }}</span>
				<select name="variant" required>
					@foreach ($product->variants as $v)
						<option value="{{ $v->id }}" @disabled(! $v->available)>
							{{ $v->name }}@if ($product->hasPriceRange()) — {{ $v->price->format() }}@endif
							@unless ($v->available) ({{ __('fourthwall::shop.sold_out') }})@endunless
						</option>
					@endforeach
				</select>
			</label>
		@endif
		@if ($quantity)
			<label class="fw-add__qty">
				<span>{{ __('fourthwall::shop.quantity') }}</span>
				<input type="number" name="quantity" value="1" min="1" max="99" inputmode="numeric">
			</label>
		@endif
		<button class="fw-buy" type="submit">{{ __('fourthwall::shop.add_to_cart') }}</button>
	</form>
@else
	<form {{ $attributes->class('fw-add') }} method="get" action="{{ $product->origin() }}/cart/checkout" target="_blank">
		@if ($single)
			<input type="hidden" name="products" value="{{ $product->variants[0]->id }}:1">
		@else
			<label class="fw-add__variant">
				<span>{{ __('fourthwall::shop.choose') }}</span>
				<select name="products" required>
					@foreach ($product->variants as $v)
						<option value="{{ $v->id }}:1" @disabled(! $v->available)>
							{{ $v->name }}@if ($product->hasPriceRange()) — {{ $v->price->format() }}@endif
							@unless ($v->available) ({{ __('fourthwall::shop.sold_out') }})@endunless
						</option>
					@endforeach
				</select>
			</label>
		@endif
		@foreach ($fw->linkParams($params) + array_filter(['currency' => config('fourthwall.currency')]) as $k => $v)
			<input type="hidden" name="{{ $k }}" value="{{ $v }}">
		@endforeach
		<button class="fw-buy" type="submit">{{ __('fourthwall::shop.buy') }}</button>
	</form>
@endif
