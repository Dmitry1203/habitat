@extends('layouts.site')

@section('title', $project->name.' — '.$directionName.' — Среда обитания')

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/photoswipe/5.4.4/photoswipe.css') }}">
@endpush

@section('header')
    @include('home.header')
@endsection

@section('content')
    <section class="service-hero" aria-labelledby="project-title">
        <div class="container service-hero-inner">
            <div>
                <a class="service-hero-back" href="{{ route('portfolio.index', ['group' => $group, 'direction' => $direction]) }}"><span aria-hidden="true">←</span> {{ $directionName }}</a>
                <h1 class="service-hero-title" id="project-title">{{ $project->name }}</h1>
                @if (filled($project->description))
                    <div class="portfolio-project-description">{!! nl2br(e($project->description)) !!}</div>
                @endif
            </div>
            <div class="service-hero-media">
                <img class="service-hero-photo" src="{{ asset('storage/'.$project->cover_path) }}"
                    alt="" width="800" height="600" fetchpriority="high" decoding="async">
            </div>
        </div>
    </section>

    <section class="portfolio-gallery-section" aria-label="Фотографии проекта">
        <div class="container">
            @if ($photos->isNotEmpty())
                <div class="public-portfolio-gallery" data-public-portfolio-masonry>
                    @foreach ($photos as $photo)
                        @php $size = $photoSizes[$photo->getKey()] ?? null; @endphp
                        <figure class="public-portfolio-photo">
                            @if ($size)
                                <a class="public-portfolio-photo-link" href="{{ asset('storage/'.$photo->path) }}"
                                    data-pswp-width="{{ $size[0] }}" data-pswp-height="{{ $size[1] }}"
                                    aria-label="Просмотреть фото {{ $loop->iteration }} проекта {{ $project->name }}">
                            @endif
                                <img src="{{ asset('storage/'.$photo->path) }}" alt="{{ $project->name }} — фото {{ $loop->iteration }}" decoding="async"
                                    @if ($size) width="{{ $size[0] }}" height="{{ $size[1] }}" @endif>
                            @if ($size)
                                </a>
                            @endif
                        </figure>
                    @endforeach
                </div>
            @else
                <p class="text-body-secondary">Фотографии пока не добавлены.</p>
            @endif
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/masonry.min.js') }}" defer></script>
    <script src="{{ asset('vendor/photoswipe/5.4.4/photoswipe.umd.min.js') }}" defer></script>
    <script src="{{ asset('vendor/photoswipe/5.4.4/photoswipe-lightbox.umd.min.js') }}" defer></script>
@endpush
