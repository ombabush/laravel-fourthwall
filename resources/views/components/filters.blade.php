{{-- A filter bar for a product list. Plain GET: a filtered list is a URL,
     the back button works, nothing needs JavaScript. Pair it with
     ProductQuery::fromRequest() on the same page.

     @php($q = Fourthwall::products('all'))
     <x-fourthwall::filters :facets="$q->facets()" />
     <x-fourthwall::shelf :products="$q->fromRequest(request())->get()" /> --}}
@props(['facets', 'sorts' => ['featured', 'price', '-price', 'newest', 'name']])
<form {{ $attributes->class('fw-filters') }} method="get" action="{{ url()->current() }}">
	<input class="fw-filters__q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('fourthwall::shop.search') }}">

	@if (count($facets['colors']) > 1)
		<fieldset class="fw-filters__group">
			<legend>{{ __('fourthwall::shop.color') }}</legend>
			@foreach ($facets['colors'] as $color => $n)
				<label><input type="checkbox" name="color[]" value="{{ $color }}" @checked(in_array($color, (array) request('color'), true))> {{ $color }}</label>
			@endforeach
		</fieldset>
	@endif

	@if (count($facets['sizes']) > 1)
		<fieldset class="fw-filters__group">
			<legend>{{ __('fourthwall::shop.size') }}</legend>
			@foreach ($facets['sizes'] as $size => $n)
				<label><input type="checkbox" name="size[]" value="{{ $size }}" @checked(in_array($size, (array) request('size'), true))> {{ $size }}</label>
			@endforeach
		</fieldset>
	@endif

	@if ($facets['min'] && $facets['max'] && $facets['min']->minor !== $facets['max']->minor)
		<fieldset class="fw-filters__group fw-filters__price">
			<legend>{{ __('fourthwall::shop.price') }}</legend>
			<input type="number" name="min" value="{{ request('min') }}" placeholder="{{ $facets['min']->decimal() }}" step="1" min="0">
			<span>–</span>
			<input type="number" name="max" value="{{ request('max') }}" placeholder="{{ $facets['max']->decimal() }}" step="1" min="0">
		</fieldset>
	@endif

	<label class="fw-filters__group">
		<span>{{ __('fourthwall::shop.sort') }}</span>
		<select name="sort">
			@foreach ($sorts as $s)
				<option value="{{ $s === 'featured' ? '' : $s }}" @selected(request('sort', '') === ($s === 'featured' ? '' : $s))>{{ __('fourthwall::shop.sort_'.ltrim(str_replace('-', 'desc_', $s), '_')) }}</option>
			@endforeach
		</select>
	</label>

	<label class="fw-filters__group"><input type="checkbox" name="available" value="1" @checked(request()->boolean('available'))> {{ __('fourthwall::shop.in_stock') }}</label>

	<button type="submit">{{ __('fourthwall::shop.apply') }}</button>
	@if (request()->hasAny(['q', 'color', 'size', 'min', 'max', 'sort', 'available']))
		<a href="{{ url()->current() }}">{{ __('fourthwall::shop.reset') }}</a>
	@endif
</form>
