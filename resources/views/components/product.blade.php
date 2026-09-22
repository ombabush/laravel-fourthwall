{{-- A whole product: pictures, name, price, description, and a «buy» that is
     a plain GET form — the variant picker's value IS the checkout parameter
     (`products=variantId:1`), so it works with JavaScript switched off. --}}
@props(['product', 'params' => [], 'width' => 900])
@php($fw = app(\Ombabush\Fourthwall\Fourthwall::class))
@php($buyable = $product->variants()->where('available', true))
<article {{ $attributes->class(['fw-product', 'is-sold-out' => ! $product->available]) }}>
	<div class="fw-product__images">
		@foreach ($product->images as $i => $img)
			<img src="{{ $img->width($width) }}" alt="{{ $img->alt ?: $product->name }}"
			     @if ($i > 0) loading="lazy" @endif
			     @if ($img->width) width="{{ $img->width }}" height="{{ $img->height }}" @endif>
		@endforeach
	</div>

	<div class="fw-product__body">
		<h1 class="fw-product__name">{{ $product->name }}</h1>
		<x-fourthwall::price :product="$product" class="fw-product__price" />

		@if ($product->colors())
			<p class="fw-product__colors">
				@foreach ($product->swatches() as $color => $swatch)
					<span class="fw-swatch" title="{{ $color }}" @if ($swatch) style="--fw-swatch: {{ $swatch }}" @endif>{{ $color }}</span>
				@endforeach
			</p>
		@endif

		@if ($product->isBundle() || $product->variants()->isEmpty())
			<a class="fw-buy" href="{{ $fw->productLink($product, $params) }}" target="_blank" rel="noopener">{{ __('fourthwall::shop.view_in_shop') }}</a>
		@elseif ($buyable->isEmpty())
			<p class="fw-product__soldout">{{ __('fourthwall::shop.sold_out') }}</p>
		@else
			<form class="fw-product__form" method="get" action="{{ $product->origin() }}/cart/checkout" target="_blank">
				<label class="fw-product__label">
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
				@foreach ($fw->linkParams($params) + array_filter(['currency' => config('fourthwall.currency')]) as $k => $v)
					<input type="hidden" name="{{ $k }}" value="{{ $v }}">
				@endforeach
				<button class="fw-buy" type="submit">{{ __('fourthwall::shop.buy') }}</button>
			</form>
		@endif

		@if ($product->description)
			<div class="fw-product__description">{!! $product->descriptionHtml() !!}</div>
		@endif

		<p class="fw-product__shop"><a href="{{ $product->url }}" target="_blank" rel="noopener">{{ __('fourthwall::shop.view_in_shop') }}</a></p>
	</div>
</article>
