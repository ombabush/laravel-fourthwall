{{-- A price, as a tag reads it: «$15», «from $15» when the size changes it,
     the old price struck through when it is on sale. --}}
@props(['product'])
<span {{ $attributes->class('fw-price') }}>
	@if ($product->onSale())
		<s class="fw-price__was">{{ $product->compareAt->format() }}</s>
	@endif
	@if ($product->hasPriceRange())
		<span class="fw-price__from">{{ __('fourthwall::shop.from') }}</span>
	@endif
	<span class="fw-price__now">{{ $product->price->format() }}</span>
</span>
