{{-- An advert: one product, big, with a line of your own and a button.

     <x-fourthwall::banner slug="mosquito-scan-1993-black-print-t-shirt"
                           headline="The 1993 scan, on a shirt" />
     <x-fourthwall::banner collection="archive" />      a random one from it, each render

     The button goes straight to checkout when there is one obvious thing to
     buy, and to the product otherwise. --}}
@props(['product' => null, 'slug' => null, 'collection' => null, 'headline' => null, 'text' => null, 'cta' => null, 'params' => [], 'width' => 1200])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$product ??= $slug ? $fw->product($slug) : $fw->products($collection)->available()->inRandomOrder()->first();
@endphp
@if ($product)
	<aside {{ $attributes->class('fw-banner') }}>
		@if ($img = $product->image())
			<a class="fw-banner__img" href="{{ $fw->productLink($product, $params) }}">
				<img src="{{ $img->width($width) }}" alt="{{ $img->alt ?: $product->name }}" loading="lazy">
			</a>
		@endif
		<div class="fw-banner__body">
			<p class="fw-banner__headline">{{ $headline ?? $product->name }}</p>
			@if ($text ?? $product->excerpt(140))
				<p class="fw-banner__text">{{ $text ?? $product->excerpt(140) }}</p>
			@endif
			<p class="fw-banner__cta">
				<x-fourthwall::price :product="$product" />
				<a class="fw-buy" href="{{ $fw->productLink($product, $params) }}"
				   @unless (str_starts_with($fw->productLink($product, $params), url('/'))) target="_blank" rel="noopener" @endunless>{{ $cta ?? __('fourthwall::shop.buy') }}</a>
			</p>
		</div>
	</aside>
@endif
