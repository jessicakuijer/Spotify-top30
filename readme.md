### Spotify Playlist Generator

Based on the user's top tracks, this program will generate a playlist of the top 30 song that the user has listened to the past 4 weeks. The playlist will be created on the user's Spotify account.
NEW : you can now create a playlist of recoomendations based on your top tracks and also an other playlist with AI recommendations.

## How to use

1. Clone the repository
2. Install the requirements : PHP ^8.0, Composer, and the Symfony CLI to run Symfony7 commands
3. Run `composer install` then open the symfony server with `symfony server:start` (or `php -S localhost:8000 -t public`)  
3. (bis) Build the assets with bin/console asset-map:compile
4. Create a Spotify application on the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard/applications)
5. Add `http://localhost:8000/callback` as a redirect URI in the Spotify application settings
6. Create a `.env.local` file and add the following variables:
    - `SPOTIFY_CLIENT_ID` : The client ID of the Spotify application
    - `SPOTIFY_CLIENT_SECRET` : The client secret of the Spotify application
    - `SPOTIFY_REDIRECT_URI` : The redirect URI of the Spotify application
    - `SPOTIFY_PLAYLIST_ID` : The ID of the playlist that will be used to store the generated playlist
7. Go to `http://localhost:8000` and connect to your Spotify account when prompted
8. Refresh `http://localhost:8000` to generate your playlist

## Documentation
- [Spotify API](https://developer.spotify.com/documentation/web-api/)
- [Spotify-web-api-bundle](https://github.com/calliostro/spotify-web-api-bundle)
- [Spotify Web API PHP](https://github.com/jwilsson/spotify-web-api-php)

## Demo
- TBA

## Credits
- [Yoan Bernabeu](https://www.youtube.com/watch?v=tACijIGxNtk)