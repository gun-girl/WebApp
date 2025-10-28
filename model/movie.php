<?php
class Movie {
    public int $id;
    public string $title;
    public string $year;
    public ?string $genre;
    public ?string $director;
    public ?string $plot;
    public ?string $poster_url;

    public function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->title = $data['title'];
        $this->year = $data['year'];
        $this->genre = $data['genre'] ?? null;
        $this->director = $data['director'] ?? null;
        $this->plot = $data['plot'] ?? null;
        $this->poster_url = $data['poster_url'] ?? null;
   }

   ///UTILITY METHODS CAN BE ADDED HERE///
   public static function getMoviesFromArray(array $dataArray): array {
       $movies = [];
       foreach ($dataArray as $data) {
           $movies[] = new Movie($data);
       }
       return $movies;
   }
}