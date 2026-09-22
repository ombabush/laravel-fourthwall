{{-- examples/shop.blade.php — the views the routes in routes.php render,
     one file for reading; split them up in your app. --}}

{{-- ── shop/index ─────────────────────────────────────────────── --}}
<x-fourthwall::styles />   {{-- optional: or style the fw-* classes yourself --}}

<x-fourthwall::shelf :products="$featured" heading="New" />
<x-fourthwall::shelf :products="$rest" heading="Everything else" size="small" />

{{-- ── anywhere: three from a collection, or an advert ────────── --}}
<x-fourthwall::shelf collection="archive" :limit="3" available :more="route('shop')" />
<x-fourthwall::banner slug="mosquito-scan-1993-black-print-t-shirt"
                      headline="The 1993 scan, on a shirt"
                      :params="['utm_campaign' => 'homepage-banner']" />
<x-fourthwall::banner collection="archive" />  {{-- a random one each render --}}

{{-- ── shop/catalogue ─────────────────────────────────────────── --}}
<x-fourthwall::filters :facets="$facets" />
<x-fourthwall::shelf :products="$products" />
{{ $products->links() }}

{{-- ── shop/product ───────────────────────────────────────────── --}}
@section('title', $product->name)
@section('description', $product->excerpt())
<x-fourthwall::product :product="$product" />
<x-fourthwall::shelf :products="$related" heading="You may also like" size="small" />

{{-- ── shop/donate ────────────────────────────────────────────── --}}
<x-fourthwall::donate :amounts="[5, 10, 25, 50]" />

{{-- ── your own markup, with the same objects ─────────────────── --}}
@foreach (Fourthwall::products('archive')->sortBy('price')->get() as $p)
	<a href="{{ Fourthwall::productLink($p) }}">
		<img src="{{ $p->image()?->width(400) }}" alt="{{ $p->name }}">
		{{ $p->name }} — {{ $p->hasPriceRange() ? 'from ' : '' }}{{ $p->price }}
		@foreach ($p->colors() as $c) <span>{{ $c }}</span> @endforeach
	</a>
	<a href="{{ Fourthwall::buyLink($p) }}">Buy now</a>
@endforeach
