{{-- A whole product: pictures, name, price, description, and
     <x-fourthwall::add-to-cart> — into the site's cart where there is one,
     straight into checkout where there is not. Both are plain forms and work
     with JavaScript switched off. --}}
@props(['product', 'params' => [], 'width' => 900])
@php($fw = app(\Ombabush\Fourthwall\Fourthwall::class))
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

		<x-fourthwall::add-to-cart :product="$product" :params="$params" class="fw-product__form" />

		@if ($product->description)
			<div class="fw-product__description">{!! $product->descriptionHtml() !!}</div>
		@endif

		<p class="fw-product__shop"><a href="{{ $product->url }}" target="_blank" rel="noopener">{{ __('fourthwall::shop.view_in_shop') }}</a></p>
	</div>
</article>
