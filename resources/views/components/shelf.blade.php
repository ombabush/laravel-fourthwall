{{-- A row of products, droppable into any page.

     <x-fourthwall::shelf />                                    every product
     <x-fourthwall::shelf collection="archive" :limit="3" />    three from one
     <x-fourthwall::shelf :collection="['a','b']" sort="price" />
     <x-fourthwall::shelf :products="$myOwnSelection" size="small" />

     Renders NOTHING when there is nothing to show — no heading over an empty
     row, no error when the shop is not configured. --}}
@props([
	'products' => null,
	'collection' => null,
	'limit' => null,
	'sort' => null,
	'random' => false,
	'available' => false,
	'except' => [],
	'heading' => null,
	'size' => 'default',
	'params' => [],
	'more' => null,
])
@php
	if ($products === null) {
		$q = app(\Ombabush\Fourthwall\Fourthwall::class)->products($collection)->sortBy($sort);
		if ($available) $q->available();
		if ($except) $q->except(...(array) $except);
		if ($random) $q->inRandomOrder();
		if ($limit) $q->take((int) $limit);
		$products = $q->get();
	}
	$products = collect($products);
@endphp
@if ($products->isNotEmpty())
	<section {{ $attributes->class(['fw-shelf', 'fw-shelf--'.$size]) }}>
		@if ($heading)
			<h2 class="fw-shelf__heading">{{ $heading }}</h2>
		@endif
		<div class="fw-shelf__row">
			@foreach ($products as $product)
				<x-fourthwall::card :product="$product" :size="$size" :params="$params" />
			@endforeach
		</div>
		@if ($more)
			<p class="fw-shelf__more"><a href="{{ $more }}">{{ __('fourthwall::shop.see_all') }}</a></p>
		@endif
	</section>
@endif
