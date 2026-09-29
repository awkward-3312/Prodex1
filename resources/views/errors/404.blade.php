@extends('errors.minimal')
@section('code', '404')
@section('card_variant', 'card--space')
@section('title', __('errors.404_title'))
@section('message', __('errors.404_message'))
@section('illustration')
    {{-- Puramente decorativo: aria-hidden evita que un lector de pantalla anuncie
         estrellas o partes del astronauta. El contenido real está en la tarjeta. --}}
    <div class="illustration" aria-hidden="true">
        <div class="pxerr-orbit"></div>
        <div class="pxerr-glow"></div>
        <div class="pxerr-stars">
            <div class="pxerr-starlayer pxerr-starlayer--1">
                <span class="pxerr-star pxerr-star--lg" style="left:6%;top:18%;animation-duration:7s"></span>
                <span class="pxerr-star pxerr-star--md" style="left:88%;top:12%;animation-duration:8.5s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:34%;top:68%;animation-duration:6.2s"></span>
                <span class="pxerr-star pxerr-star--md" style="left:60%;top:82%;animation-duration:9.5s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:16%;top:46%;animation-duration:7.8s"></span>
                <span class="pxerr-star pxerr-star--lg" style="left:76%;top:55%;animation-duration:8s"></span>
            </div>
            <div class="pxerr-starlayer pxerr-starlayer--2">
                <span class="pxerr-star pxerr-star--sm" style="left:46%;top:8%;animation-duration:10s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:92%;top:40%;animation-duration:8.8s"></span>
                <span class="pxerr-star pxerr-star--md" style="left:10%;top:74%;animation-duration:9.2s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:66%;top:30%;animation-duration:7.4s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:28%;top:90%;animation-duration:11s"></span>
                <span class="pxerr-star pxerr-star--md" style="left:82%;top:78%;animation-duration:9.8s"></span>
            </div>
            <div class="pxerr-starlayer pxerr-starlayer--3">
                <span class="pxerr-star pxerr-star--sm" style="left:20%;top:30%;animation-duration:12s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:54%;top:60%;animation-duration:10.5s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:72%;top:15%;animation-duration:13s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:40%;top:85%;animation-duration:11.5s"></span>
                <span class="pxerr-star pxerr-star--sm" style="left:95%;top:64%;animation-duration:12.5s"></span>
            </div>
        </div>
        <div class="pxerr-astro">
            <div class="pxerr-astro__scale">
                <div class="pxerr-astro__schoolbag"></div>
                <div class="pxerr-astro__body">
                    <div class="pxerr-astro__panel"></div>
                </div>
                <div class="pxerr-astro__arm pxerr-astro__arm--left"></div>
                <div class="pxerr-astro__arm pxerr-astro__arm--right"></div>
                <div class="pxerr-astro__leg pxerr-astro__leg--left"></div>
                <div class="pxerr-astro__leg pxerr-astro__leg--right"></div>
                <div class="pxerr-astro__head"></div>
            </div>
        </div>
    </div>
@endsection
