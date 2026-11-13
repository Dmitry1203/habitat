@extends('layouts.site')

@section('title', $groupData['name'].' — наши работы — Среда обитания')

@section('header')
    @include('home.header')
@endsection

@section('content')
    <section class="works page-section portfolio-index" aria-labelledby="portfolio-group-title">
        <div class="container">
            <a class="service-hero-back" href="{{ url('/') }}#portfolio"><span aria-hidden="true">←</span> Все направления</a>
            <div class="section-heading">
                <h1 class="section-title text-break" id="portfolio-group-title">{{ $groupData['name'] }}</h1>
            </div>
            <div @class([
                'works-grid works-grid--projects',
                'works-grid--two-columns' => in_array(count($groupData['directions']), [2, 4], true),
            ])>
                @foreach ($groupData['directions'] as $direction => $name)
                    <a class="work-card" href="{{ route('portfolio.index', ['group' => $group, 'direction' => $direction]) }}">
                        <img class="work-card-image" src="{{ asset('images/banners/'.config('portfolio.direction_images.'.$direction, 'apart.jpg')) }}"
                            alt="{{ $name }}" width="800" height="600" loading="lazy" decoding="async">
                        <span class="work-card-body">
                            <h2 class="work-card-title">{{ $name }}</h2>
                            <span class="work-card-arrow" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endsection
