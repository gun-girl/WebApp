<?php
class Vote {
    public int $id;
    public string $title;
    public ?string $poster_url;
    public ?string $genre;
    public ?string $director;
    public ?string $plot;
    public int $year;
    public int $rating;
    public ?int $writing;
    public ?int $direction;
    public ?int $sound;
    public ?int $music;
    public ?int $novelty;

    public function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->title = $data['title'];
        $this->year = (int)$data['year'];
        $this->poster_url = $data['poster_url'] ?? null;
        $this->genre = $data['genre'] ?? null;
        $this->director = $data['director'] ?? null;
        $this->plot = $data['plot'] ?? null;
        $this->rating = (int)($data['rating'] ?? 0);
        $this->writing = (int)($data['writing'] ?? 0);
        $this->direction = (int)($data['direction'] ?? 0);
        $this->sound = (int)($data['sound'] ?? 0);
        $this->music = (int)($data['music'] ?? 0);
        $this->novelty = (int)($data['novelty'] ?? 0);
    }

    public function render(): string {
        $poster = $this->poster_url
            ? "<img src='{$this->poster_url}' alt='{$this->title}' class='poster'>"
            : "<div class='poster placeholder'>No Image</div>";

        return "
        <div class='vote-card'>
            {$poster}
            <div class='vote-info'>
                <h3>{$this->title} ({$this->year})</h3>
                <p><strong>Genre:</strong> {$this->genre}</p>
                <p><strong>Director:</strong> {$this->director}</p>
                <p><strong>Plot:</strong> {$this->plot}</p>
                <p><strong>Overall Rating:</strong> {$this->rating}/10</p>
                <ul>
                    <li><strong>Writing:</strong> {$this->writing}</li>
                    <li><strong>Direction:</strong> {$this->direction}</li>
                    <li><strong>Sound:</strong> {$this->sound}</li>
                    <li><strong>Music:</strong> {$this->music}</li>
                    <li><strong>Novelty:</strong> {$this->novelty}</li>
                </ul>
            </div>
        </div>";
    }
}
