@extends('layouts.site')

@section('title', $directionName.' — наши работы — Среда обитания')

@section('header')
    @include('home.header')
@endsection

@section('content')
    <section class="works page-section portfolio-index" aria-labelledby="portfolio-title">
        <div class="container">
            <a class="service-hero-back" href="{{ route('portfolio.group', ['group' => $group]) }}"><span aria-hidden="true">←</span> {{ $groupData['name'] }}</a>
            <div class="section-heading">
                <h1 class="section-title text-break" id="portfolio-title">{{ $directionName }}</h1>
                @if ($projects->isNotEmpty())
                    <p class="section-lead">Выберите проект, чтобы посмотреть описание и фотографии.</p>
                @endif
            </div>
            @if ($projects->isNotEmpty())
                <div class="works-grid works-grid--projects">
                    @foreach ($projects as $project)
                        <a class="work-card" href="{{ route('portfolio.show', ['group' => $group, 'direction' => $direction, 'portfolio' => $project]) }}">
                            <img class="work-card-image" src="{{ asset('storage/'.$project->cover_path) }}"
                                alt="{{ $project->name }}" width="800" height="600" loading="lazy" decoding="async">
                            <span class="work-card-body">
                                <h2 class="work-card-title">{{ $project->name }}</h2>
                                <span class="work-card-arrow" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="section-lead">Проекты пока не добавлены.</p>
            @endif
        </div>
    </section>
@endsection
