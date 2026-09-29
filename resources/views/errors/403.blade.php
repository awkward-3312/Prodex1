@extends('errors.minimal')
@section('code', '403')
@section('card_variant', 'card--pencil')
@section('title', __('errors.403_title'))
@section('message', __('errors.403_message'))
@section('illustration')
    {{-- Puramente decorativo: aria-hidden + focusable="false" evita que un lector de pantalla
         anuncie circle/polygon/eraser. Geometría 1:1 de Uiverse (gustavofusco/rare-pug-90):
         viewBox, radios, stroke-dasharray y transforms sin modificar. Solo la paleta se mapea
         a navy/cyan PRODEX (wood/graphite del punto se conservan, para que siga leyéndose
         como lápiz y no "todo cyan"). --}}
    <div class="illustration" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" height="200px" width="200px" viewBox="0 0 200 200" class="pencil" aria-hidden="true" focusable="false">
            <defs>
                <clipPath id="pxerr403-pencil-eraser">
                    <rect height="30" width="30" ry="5" rx="5"></rect>
                </clipPath>
            </defs>
            <circle transform="rotate(-113,100,100)" stroke-linecap="round" stroke-dashoffset="439.82" stroke-dasharray="439.82 439.82" stroke-width="2" stroke="currentColor" fill="none" r="70" class="pencil__stroke"></circle>
            <g transform="translate(100,100)" class="pencil__rotate">
                <g fill="none">
                    <circle transform="rotate(-90)" stroke-dashoffset="402" stroke-dasharray="402.12 402.12" stroke-width="30" stroke="#12467a" r="64" class="pencil__body1"></circle>
                    <circle transform="rotate(-90)" stroke-dashoffset="465" stroke-dasharray="464.96 464.96" stroke-width="10" stroke="#06b6d4" r="74" class="pencil__body2"></circle>
                    <circle transform="rotate(-90)" stroke-dashoffset="339" stroke-dasharray="339.29 339.29" stroke-width="10" stroke="#0b1f3a" r="54" class="pencil__body3"></circle>
                </g>
                <g transform="rotate(-90) translate(49,0)" class="pencil__eraser">
                    <g class="pencil__eraser-skew">
                        <rect height="30" width="30" ry="5" rx="5" fill="#8fd7e6"></rect>
                        <rect clip-path="url(#pxerr403-pencil-eraser)" height="30" width="5" fill="#3fb6cf"></rect>
                        <rect height="20" width="30" fill="#eef2f5"></rect>
                        <rect height="20" width="15" fill="#b9c2c9"></rect>
                        <rect height="20" width="5" fill="#d6dde1"></rect>
                        <rect height="2" width="30" y="6" fill="rgba(15,23,42,0.2)"></rect>
                        <rect height="2" width="30" y="13" fill="rgba(15,23,42,0.2)"></rect>
                    </g>
                </g>
                <g transform="rotate(-90) translate(49,-30)" class="pencil__point">
                    <polygon points="15 0,30 30,0 30" fill="hsl(33,90%,70%)"></polygon>
                    <polygon points="15 0,6 30,0 30" fill="hsl(33,90%,50%)"></polygon>
                    <polygon points="15 0,20 10,10 10" fill="hsl(223,10%,10%)"></polygon>
                </g>
            </g>
        </svg>
    </div>
@endsection
