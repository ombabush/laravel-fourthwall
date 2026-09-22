{{-- The optional stylesheet, inlined once per page. Structure only — a grid,
     sizes, a sold-out state — themed through custom properties, so a host
     restyles it by setting --fw-* rather than overriding selectors:

     .my-page { --fw-ink: #000; --fw-paper: #fff; --fw-muted: #777; --fw-radius: 0 }

     Skip this component entirely and write your own; the markup does not
     depend on it. --}}
@once
<style>{!! \Ombabush\Fourthwall\FourthwallServiceProvider::css() !!}</style>
@endonce
