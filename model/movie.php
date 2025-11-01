<?php
// model/Movie.php
class Movie {
    public int $id;
    public string $title;
    public int $year;
    public ?string $poster_url;

    public function __construct(array $data) {
        $this->id = (int)($data['id'] ?? 0);
        $this->title = $data['title'] ?? '';
        $this->year = (int)($data['year'] ?? 0);
        $this->poster_url = $data['poster_url'] ?? null;
    }

    public static function getAll(mysqli $db): array {
        $rows = $db->query("SELECT * FROM movies ORDER BY year DESC, title ASC")->fetch_all(MYSQLI_ASSOC);
        return array_map(fn($r) => new Movie($r), $rows);
    }
}
?>
