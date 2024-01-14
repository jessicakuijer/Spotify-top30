<?php

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class SpotifyController extends AbstractController
{
    public function __construct(
        private readonly SpotifyWebAPI $api,
        private readonly Session $session,
        private readonly CacheItemPoolInterface $cache,
    )
    {
    }

    #[Route('/', name: 'app_spotify_update_my_playlist')]
    public function updateMyPlaylist(): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }
        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());

        $top30 = $this->api->getMyTop('tracks', [
            'limit' => 30,
            'time_range' => 'short_term',
        ]);

        $top30TracksIds = array_map(function ($track) {
            return $track->id;
        }, $top30->items);

        $playlistId = $this->getParameter('SPOTIFY_PLAYLIST_ID');
        $this->api->replacePlaylistTracks($playlistId, $top30TracksIds);

        return $this->render('spotify/index.html.twig', [
            'tracks' => $this->api->getPlaylistTracks($playlistId),
        ]);
    }

    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $cacheItem = $this->cache->getItem('spotify_access_token');
        $cacheItem->set($this->session->getAccessToken());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);
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
}
