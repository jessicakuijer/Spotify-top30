<?php

namespace App\Controller;

use SpotifyWebAPI\Session;
use Psr\Log\LoggerInterface;
use SpotifyWebAPI\SpotifyWebAPI;
use Psr\Cache\CacheItemPoolInterface;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class SpotifyController extends AbstractController
{
    private bool $isAuthenticated = false;

    private const RECOMMENDATIONS_CACHE_KEY = 'spotify_recommendations';
    
    public function __construct(
        private readonly SpotifyWebAPI $api,
        private readonly Session $session,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    private function checkAndSetAuth(): bool
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return false;
        }

        try {
            $token = $this->cache->getItem('spotify_access_token')->get();
            $this->api->setAccessToken($token);
            
            // Vérifier si le token est valide en faisant un appel simple
            $this->api->me();
            
            return true;
        } catch (\Exception $e) {
            // Si le token est expiré ou invalide, on le supprime
            $this->cache->deleteItem('spotify_access_token');
            return false;
        }
    }

    #[Route('/', name: 'app_spotify_index')]
    public function index(): Response
    {
        $tracks = null;
        $recommendations = null;

        try {
            if ($this->checkAndSetAuth()) {
                $tracks = $this->api->getPlaylistTracks($this->getParameter('SPOTIFY_PLAYLIST_ID'));
                try {
                    $recommendations = $this->getRecommendations();
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $this->render('spotify/index.html.twig', [
            'tracks' => $tracks,
            'recommendations' => $recommendations,
            'is_authenticated' => $this->checkAndSetAuth()
        ]);
    }

    #[Route('/update', name: 'app_spotify_update_my_playlist')]
    public function updateMyPlaylist(Request $request): Response
    {
        if (!$this->checkAndSetAuth()) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        try {
            $timeRange = $request->query->get('time_range', 'short_term');
            $limit = $request->query->get('limit', 30);

            $topTracks = $this->api->getMyTop('tracks', [
                'limit' => $limit,
                'time_range' => $timeRange,
            ]);

            $topTracksIds = array_map(fn($track) => $track->id, $topTracks->items);

            $playlistId = $this->getParameter('SPOTIFY_PLAYLIST_ID');
            $this->api->replacePlaylistTracks($playlistId, $topTracksIds);

            $tracks = $this->api->getPlaylistTracks($playlistId);
            $recommendations = $this->getRecommendations(); // Changé ici

            return $this->render('spotify/update.html.twig', [
                'tracks' => $tracks,
                'time_range' => $timeRange,
                'recommendations' => $recommendations
            ]);

        } catch (\Exception $e) {
            // En cas d'erreur d'API, on redirige vers l'authentification
            return $this->redirectToRoute('app_spotify_redirect');
        }
    }

    private function getRecommendations(): ?object
    {
        if (!$this->checkAndSetAuth()) {
            throw new \RuntimeException('Authentification requise');
        }

        try {
            // Vérifier si on a déjà des recommandations en cache
            if ($this->cache->hasItem(self::RECOMMENDATIONS_CACHE_KEY)) {
                $cachedRecommendations = $this->cache->getItem(self::RECOMMENDATIONS_CACHE_KEY)->get();
                if ($cachedRecommendations) {
                    $this->logger->info('Utilisation des recommandations en cache');
                    return $cachedRecommendations;
                }
            }

            // 1. Récupérer les top tracks pour les seeds
            $this->logger->info('Récupération des top tracks...');
            $topTracks = $this->api->getMyTop('tracks', [
                'limit' => 3,
                'time_range' => 'short_term'
            ]);

            if (empty($topTracks->items)) {
                throw new \RuntimeException('Aucun titre trouvé dans votre historique');
            }

            // 2. Récupérer les top artistes pour plus de variété
            $topArtists = $this->api->getMyTop('artists', [
                'limit' => 2,
                'time_range' => 'short_term'
            ]);

            // Préparer les seeds
            $seedTracks = array_map(fn($track) => $track->id, array_slice($topTracks->items, 0, 2));
            $seedArtists = !empty($topArtists->items) ? [($topArtists->items[0]->id ?? null)] : [];

            // 3. Obtenir les recommandations
            $this->logger->info('Récupération des recommandations...');
            $recommendations = $this->api->getRecommendations([
                'seed_tracks' => $seedTracks,
                'seed_artists' => array_filter($seedArtists), // Enlever les valeurs null
                'limit' => 20,
                'min_popularity' => 20,
                'target_popularity' => 60
            ]);

            if (empty($recommendations->tracks)) {
                throw new \RuntimeException('Aucune recommandation disponible');
            }

            // Stocker en cache pour 1 heure
            $cacheItem = $this->cache->getItem(self::RECOMMENDATIONS_CACHE_KEY);
            $cacheItem->set($recommendations);
            $cacheItem->expiresAfter(3600);
            $this->cache->save($cacheItem);

            return $recommendations;

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des recommandations: ' . $e->getMessage());
            throw new \RuntimeException('Erreur lors de la récupération des recommandations: ' . $e->getMessage());
        }
    }

    #[Route('/recommendations', name: 'app_spotify_recommendations')]
    public function getRecommendationsPage(): Response
    {
        if (!$this->checkAndSetAuth()) {
            $this->addFlash('error', 'Veuillez vous connecter à Spotify');
            return $this->redirectToRoute('app_spotify_redirect');
        }

        try {
            $recommendations = $this->getRecommendations();
            
            // Récupérer les informations des seeds pour l'affichage
            $topTracks = $this->api->getMyTop('tracks', ['limit' => 3]);
            
            return $this->render('spotify/recommendations.html.twig', [
                'recommendations' => $recommendations,
                'seed_tracks' => array_slice($topTracks->items, 0, 2)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des recommandations: ' . $e->getMessage());
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_spotify_index');
        }
    }

    #[Route('/create-playlist-from-recommendations', name: 'app_spotify_create_recommendations_playlist')]
    public function createPlaylistFromRecommendations(): Response
    {
        if (!$this->checkAndSetAuth()) {
            $this->addFlash('error', 'Veuillez vous connecter à Spotify');
            return $this->redirectToRoute('app_spotify_redirect');
        }

        try {
            $recommendations = $this->getRecommendations();
            
            $user = $this->api->me();
            $playlist = $this->api->createPlaylist([
                'name' => 'Recommendations du ' . date('d/m/Y'),
                'description' => 'Playlist générée automatiquement basée sur vos titres préférés',
                'public' => false
            ]);

            $trackIds = array_map(fn($track) => $track->id, $recommendations->tracks);
            $this->api->addPlaylistTracks($playlist->id, $trackIds);

            $this->cache->deleteItem(self::RECOMMENDATIONS_CACHE_KEY);

            $this->addFlash('success', 'Nouvelle playlist créée avec succès !');
            return $this->redirectToRoute('app_spotify_recommendations');

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la création de la playlist: ' . $e->getMessage());
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_spotify_index');
        }
    }

    #[Route('/create-playlist-from-ai-recommendations', name: 'app_spotify_create_ai_recommendations_playlist')]
    public function createPlaylistFromAIRecommendations(): Response
    {
        if (!$this->checkAndSetAuth()) {
            $this->addFlash('error', 'Veuillez vous connecter à Spotify');
            return $this->redirectToRoute('app_spotify_redirect');
        }

        try {
            // Utilisation spécifique des recommandations IA
            $aiRecommendations = $this->getAIRecommendations();
            
            // Création de la playlist
            $user = $this->api->me();
            $playlist = $this->api->createPlaylist([
                'name' => 'Playlist IA du ' . date('d/m/Y'),
                'description' => 'Playlist générée par analyse IA de vos goûts musicaux (énergie, dansabilité, genres préférés)',
                'public' => false
            ]);

            // Ajout des titres
            $trackIds = array_map(fn($track) => $track->id, $aiRecommendations->tracks);
            $this->api->addPlaylistTracks($playlist->id, $trackIds);

            $this->addFlash('success', 'Nouvelle playlist IA créée avec succès !');
            return $this->redirectToRoute('app_spotify_ai_recommendations');

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la création de la playlist IA: ' . $e->getMessage());
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_spotify_index');
        }
    }

    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            $this->addFlash('error', 'Erreur d\'authentification Spotify');
            return $this->redirectToRoute('app_spotify_index');
        }

        $cacheItem = $this->cache->getItem('spotify_access_token');
        $cacheItem->set($this->session->getAccessToken());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);
        
        $this->addFlash('success', 'Connexion à Spotify réussie !');
        return $this->redirectToRoute('app_spotify_update_my_playlist');
    }

    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        $options = [
            'scope' => [
                'user-read-email',
                'user-read-private',
                'playlist-read-private',
                'playlist-modify-private',
                'playlist-modify-public',
                'user-top-read',
            ],
        ];
        return $this->redirect($this->session->getAuthorizeUrl($options));
    }

    private function getAIRecommendations(): object
    {
        if (!$this->checkAndSetAuth()) {
            throw new \RuntimeException('Authentification requise');
        }

        try {
            // 1. Analyse des habitudes d'écoute sur différentes périodes
            $shortTermTracks = $this->api->getMyTop('tracks', ['limit' => 10, 'time_range' => 'short_term']);
            $mediumTermTracks = $this->api->getMyTop('tracks', ['limit' => 10, 'time_range' => 'medium_term']);
            $longTermTracks = $this->api->getMyTop('tracks', ['limit' => 10, 'time_range' => 'long_term']);

            // 2. Récupération des caractéristiques audio des titres préférés
            $allTrackIds = [];
            foreach ([$shortTermTracks, $mediumTermTracks, $longTermTracks] as $tracks) {
                foreach ($tracks->items as $track) {
                    $allTrackIds[] = $track->id;
                }
            }

            // Utilisation de getMultipleAudioFeatures pour récupérer les caractéristiques de plusieurs titres
            $audioFeatures = [];
            $chunks = array_chunk($allTrackIds, 100); // L'API a une limite de 100 titres par requête
            
            foreach ($chunks as $chunk) {
                $response = $this->api->getMultipleAudioFeatures($chunk);
                if ($response && $response->audio_features) {
                    $audioFeatures = array_merge($audioFeatures, array_filter($response->audio_features)); // Filtre des valeurs null
                }
            }

            // 3. Analyse des caractéristiques moyennes préférées
            $avgFeatures = $this->calculateAverageFeatures($audioFeatures);

            // 4. Obtenir les genres préférés
            $topArtists = $this->api->getMyTop('artists', ['limit' => 20]);
            $genrePreferences = $this->analyzeGenrePreferences($topArtists->items);

            // 5. Génération des recommandations
            $recommendations = [];
            
            // 5.1 Recommandations basées sur les goûts récents
            if (!empty($shortTermTracks->items)) {
                $seedTracks = array_slice(array_map(fn($track) => $track->id, $shortTermTracks->items), 0, 2);
                $recentBased = $this->api->getRecommendations([
                    'seed_tracks' => $seedTracks,
                    'target_danceability' => $avgFeatures['danceability'],
                    'target_energy' => $avgFeatures['energy'],
                    'target_valence' => $avgFeatures['valence'],
                    'limit' => 10
                ]);
                if (isset($recentBased->tracks)) {
                    $recommendations = array_merge($recommendations, $recentBased->tracks);
                }
            }

            // 5.2 Recommandations basées sur les genres préférés
            $topGenres = array_slice(array_keys($genrePreferences), 0, 2);
            if (!empty($topGenres)) {
                $genreBased = $this->api->getRecommendations([
                    'seed_genres' => $topGenres,
                    'target_popularity' => 70,
                    'min_popularity' => 20,
                    'limit' => 10
                ]);
                if (isset($genreBased->tracks)) {
                    $recommendations = array_merge($recommendations, $genreBased->tracks);
                }
            }

            // 6. Filtrage des doublons et ordre aléatoire
            $recommendations = $this->filterAndRandomize($recommendations);

            // 7. Préparer la réponse
            return (object)[
                'tracks' => array_slice($recommendations, 0, 20),
                'insights' => [
                    'preferred_genres' => array_slice($genrePreferences, 0, 5, true),
                    'audio_profile' => $avgFeatures,
                    'mood_analysis' => $this->analyzeMood($avgFeatures)
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('Erreur IA recommendations: ' . $e->getMessage());
            throw new \RuntimeException('Erreur lors de l\'analyse IA: ' . $e->getMessage());
        }
    }

    private function calculateAverageFeatures(array $audioFeatures): array
    {
        if (empty($audioFeatures)) {
            return [
                'danceability' => 0.5,
                'energy' => 0.5,
                'valence' => 0.5,
                'instrumentalness' => 0.5,
                'acousticness' => 0.5,
                'tempo' => 120
            ];
        }

        $sum = [
            'danceability' => 0,
            'energy' => 0,
            'valence' => 0,
            'instrumentalness' => 0,
            'acousticness' => 0,
            'tempo' => 0
        ];
        $count = count($audioFeatures);

        foreach ($audioFeatures as $feature) {
            if ($feature === null) continue;
            foreach ($sum as $key => $value) {
                if (isset($feature->$key)) {
                    $sum[$key] += $feature->$key;
                }
            }
        }

        return array_map(fn($value) => $value / $count, $sum);
    }

    private function filterAndRandomize(array $tracks): array
    {
        // Suppression des doublons par ID
        $uniqueTracks = [];
        foreach ($tracks as $track) {
            $uniqueTracks[$track->id] = $track;
        }
        
        // Conversion en tableau et mélange
        $tracks = array_values($uniqueTracks);
        shuffle($tracks);
        
        return $tracks;
    }

    #[Route('/ai-recommendations', name: 'app_spotify_ai_recommendations')]
    public function getAIRecommendationsPage(): Response
    {
        if (!$this->checkAndSetAuth()) {
            $this->addFlash('error', 'Veuillez vous connecter à Spotify');
            return $this->redirectToRoute('app_spotify_redirect');
        }

        try {
            $recommendations = $this->getAIRecommendations();
            
            return $this->render('spotify/ai_recommendations.html.twig', [
                'recommendations' => $recommendations->tracks,
                'insights' => $recommendations->insights
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des recommandations IA: ' . $e->getMessage());
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_spotify_index');
        }
    }

    private function analyzeGenrePreferences(array $artists): array
    {
        $genres = [];
        foreach ($artists as $artist) {
            foreach ($artist->genres as $genre) {
                if (!isset($genres[$genre])) {
                    $genres[$genre] = 0;
                }
                $genres[$genre]++;
            }
        }

        arsort($genres); // Tri par fréquence décroissante
        return $genres;
    }

    private function analyzeMood(array $features): array
    {
        // Conversiin des valeurs en pourcentages et ajout des descriptions
        $mood = [
            'Énergie' => $features['energy'] * 100,
            'Dansabilité' => $features['danceability'] * 100,
            'Positivité' => $features['valence'] * 100,
            'Acoustique' => $features['acousticness'] * 100
        ];

        // Ajout des analyses supplémentaires basées sur les combinaisons de caractéristiques
        $moodDescriptions = [];

        // Analyse de l'énergie et de la positivité
        if ($features['energy'] > 0.8 && $features['valence'] > 0.8) {
            $moodDescriptions[] = "Très énergique et positif";
        } elseif ($features['energy'] < 0.2 && $features['valence'] < 0.2) {
            $moodDescriptions[] = "Calme et mélancolique";
        }

        // Analyse de la dansabilité
        if ($features['danceability'] > 0.7) {
            $moodDescriptions[] = "Très dansant";
        }

        // Analyse du caractère acoustique
        if ($features['acousticness'] > 0.8) {
            $moodDescriptions[] = "Très acoustique";
        } elseif ($features['acousticness'] < 0.2) {
            $moodDescriptions[] = "Très électronique";
        }

        // Ajout des descriptions à la réponse
        $mood['description'] = $moodDescriptions;

        return $mood;
    }
}