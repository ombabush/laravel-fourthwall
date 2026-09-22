{{-- One product on a shelf: picture, name, price. The whole card is the
     link; where it goes is Fourthwall::productLink() — your own page for the
     product if `product_route` is set, the shop's otherwise. --}}
@props(['product', 'size' => 'default', 'params' => [], 'width' => 480])
@php($fw = app(\Ombabush\Fourthwall\Fourthwall::class))
@php($href = $fw->productLink($product, $params))
@php($external = ! str_starts_with($href, url('/')))
<article {{ $attributes->class(['fw-card', 'fw-card--'.$size, 'is-sold-out' => ! $product->available]) }}>
	<a class="fw-card__link" href="{{ $href }}" @if ($external) target="_blank" rel="noopener" @endif>
		<span class="fw-card__img">
			@if ($img = $product->image())
				<img src="{{ $img->width($width) }}" alt="{{ $img->alt ?: $product->name }}" loading="lazy"
				     @if ($img->width) width="{{ $img->width }}" height="{{ $img->height }}" @endif>
			@endif
		</span>
		<span class="fw-card__name">{{ $product->name }}</span>
		<x-fourthwall::price :product="$product" class="fw-card__price" />
		@unless ($product->available)
			<span class="fw-card__flag">{{ __('fourthwall::shop.sold_out') }}</span>
		@endunless
	</a>
</article>
