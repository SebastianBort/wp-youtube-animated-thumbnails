<?php

/*
Plugin Name: Animowane miniatury filmów z Youtube
Description: Pozwala na pobranie animowanej miniatury filmu z Youtube bez używania API
Version: 1.0.0
Author: Sebastian Bort
*/

class Youtube_Animated_Thumbnails {

        // wewnetrzna konfiguracja pluginu
        const config = [             
            
            // minimalna ilosc sekund jaka musi minac miedzy kolejnymi odpytaniami serwerow youtube
            'minimum_delay_between_api_usage' => 5, 
            
            // klucz do przechowywania informacji o dacie ostatniego zapytania do serwerow youtube            
            'last_api_usage_key' => '_yat_api_last_usage',               
            
            // klucz w postmeta do przechowywania metadanych animowanej miniatury            
            'metadata_key' => '_yat',                
            
            // katalog do zapisywania animowanych miniatur w formacie .webp (wp-content/uploads/WPISANY_FOLDER)
            'webp_dir' => 'webp',
            
            // wyrazenie regularne do wyszukiwania identyfikatora filmu w tresci postu
            'regex' => '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i',  
            
            // klucz w postmeta ktory zawiera informacje o wylaczonej miniaturze dla danego filmu   
            'disabled_animation_key' =>  'disable_anim' ,        
        ];
        
        // konfiguracja biblioteki cURL
        const curl_config = [
              
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.2; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36',
                CURLOPT_REFERER => 'https://youtube.com', 
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                    'Accept-Language: pl-PL;q=0.9',
                    'Connection: keep-alive',
                    'request-languag: pl',
                ],                   
                CURLOPT_ENCODING => 'gzip',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false, 
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,                  
                CURLOPT_COOKIEFILE => __DIR__ . '/.cookies',
                CURLOPT_COOKIEJAR => __DIR__ . '/.cookies',                  
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,                     
                CURLOPT_CONNECTTIMEOUT => 10,                  
                CURLOPT_MAXCONNECTS	 => 3,
                CURLOPT_TIMEOUT	 => 60,
                CURLOPT_TCP_FASTOPEN => false,  
        ];   

        // zmienna do przechowywania daty ostatniego zapytania do serwerow youtube
        private static $api_last_usage = null;  
       
        // funkcja zwracajaca url lokalny animowanej miniatury
        public static function get_animated_thumbnail_url($post_id, $return_fields = 'all') {
        
                // jezeli animowana miniatura jest wylaczona dla tego filmu, zwroc false
                if(intval(get_post_meta($post_id, self::config['disabled_animation_key'], true)))
                        return false;
            
                // pobranie metadanych animowanej miniatury z brazy danych
                $thumbnail_data = get_post_meta($post_id, self::config['metadata_key'], true);
                
                // jezeli brak metadanych wstaw domyslne metadane ze statusem oczekujacym
                if(empty($thumbnail_data)) {                 
                        
                        // zapelnienie tablicy domyslnymi metadanymi
                        $thumbnail_data = [                                 
                          // status: oczekuje na pobranie
                          'status' => 'waiting',                                           
                          // brak linku do pliku z miniatura
                          'webp' => '',                                            
                          // znacznik czasowy na biezaca date
                          'time' => time(),                            
                        ];

                        // zapis domyslnych metadanych do bazy danych                        
                        update_post_meta($post_id, self::config['metadata_key'], $thumbnail_data);
                }
                
                // miniatura dla wpisue nie zostala jeszcze pobrana - metadane ze statusem oczekuajcym - do sprawdzenia czy mozemy pobrac miniature
                if($thumbnail_data['status'] == 'waiting') {
                        
                        // sprawdzenie czy lokalnym w obiekcie mamy juz pobrana date ostatniego uzywania api, jezeli nie to zapisujemy w obiekcie - aby wiele razy nie odpytywac bazy danych o to samo
                        if(self::$api_last_usage === null) {
                                
                                // pobranie z bazy danych daty ostatniego polaczenia z serwerami youtube 
                                self::$api_last_usage = (int) get_option(self::config['last_api_usage_key']);
                        }                         
                        
                        // sprawdzanie czy minelo wystarczajaco duzo czasu od ostatniego uzywania api youtube 
                        if(
                                // jezeli nie bylo zapytan do serwerow youtube
                                self::$api_last_usage === 0 || 
                                
                                // jezeli od ostatniego zapytania minelo wiecej sekund niz ustawione w konfiguracji
                                time() - self::$api_last_usage > self::config['minimum_delay_between_api_usage']
                                ) {
                                
                                // pobranie danych postu aby wydobyc z niego identyfikator wideo
                                $post = get_post($post_id);
                                
                                // wyszukiwanie identyfikatora wideo w tresci postu
                                if (preg_match(self::config['regex'], $post->post_content, $match)) {
                                   
                                    // przypisanie wynikow wyszukiwania do zmiennej
                                    $video_id = $match[1];
                                }
                                
                                // nie znaleziono id wideo w tresci postu
                                if(empty($video_id)) {
                                    
                                    // ustawienie statusu na brak identyfikatora wideo
                                    $thumbnail_data['status'] = 'no_video_id'; 
                                                                                                    
                                }
                                // film zostal znaleziony w tresci postu
                                else {
                                    
                                    // zapisanie identyfikatora wideo do oddzielnego meta pola - do dalszego uzycia
                                    update_post_meta($post_id, 'video_id', $video_id);                                         
                                    
                                    // zaktualizuj date ostatniego uzycia api lokalnym obiekcie
                                    self::$api_last_usage = time();
                                    
                                    // zaktualizuj date ostatniego uzycia api w bazie danych
                                    update_option(self::config['last_api_usage_key'], self::$api_last_usage);                                     

                                    // odpytanie serwerow youtube o dany film i pobranie danych miniaturki jezeli dostepna
                                    $thumbnail_filedata = self::__get_animated_thumbnail_data($video_id);
                                    
                                    // youtube nie zwrocilo miniaturki, zmiana statusu
                                    if(empty($thumbnail_filedata)) {
                                        
                                        // ustawienie statusu na brak miniatury
                                        $thumbnail_data['status'] = 'no_thumbnail';        
                                        
                                    }
                                    // youtube zwrocilo zawartosc pliku z miniaturka, zmiana statusu oraz zapis do pliku
                                    else {
                                        
                                        // pobranie ustawien folderu uploads
                                        $uploads_settings = wp_upload_dir();    
                                        
                                        // zbudowanie lokalnej (serwerowej) sciezki do zapisu pliku
                                        $thumbnail_local_path = sprintf('%s/%s/%s.webp', $uploads_settings['basedir'], self::config['webp_dir'], $video_id);                                          

                                        // zbudowanie zdalnej sciezki URL do pliku
                                        $thumbnail_remote_path = sprintf('%s/%s/%s.webp', $uploads_settings['baseurl'], self::config['webp_dir'], $video_id);
                                        
                                        // zapis danych do pliku
                                        file_put_contents($thumbnail_local_path, $thumbnail_filedata);
                                       
                                         // ustawienie statusu na pobrany
                                        $thumbnail_data['status'] = 'ok';

                                         // ustawienie linku do miniatury
                                        $thumbnail_data['webp'] = $thumbnail_remote_path;  
                                    }                                                                  
                                }
                                
                                // ustawienie daty statusu
                                $thumbnail_data['time'] = time(); 
                                
                                // aktualizacja metadanych do bazy danych
                                update_post_meta($post_id, self::config['metadata_key'], $thumbnail_data);  
                        }
                }
                
                // todo: 
                // ponowne sprawdzanie statusow no_video_id i no_thumbnail co X czasu
                // mozliwosc kasowania statusu filmu
               
               // zwroc wybrane do animowanej miniatury
               return $return_fields == 'all' ? $thumbnail_data : (  
                    isset($thumbnail_data[$return_fields]) ? $thumbnail_data[$return_fields] : $thumbnail_data['webp']
               );
        }           
        
        // funkcja zwraca zawartosc pliku .webp z miniatura na podstawie id filmu
        public static function __get_animated_thumbnail_data($video_id) {
        
            // jezeli brak idenrtfikatora filmu, zwroc nic
            if(empty($video_id)) {
                return false;
            }
        
            // budowanie adresu URL wyszukiwania na Youtube, aby wybrany film byl na liscie jako pierwszy
            $search_page_url = sprintf('https://www.youtube.com/results?search_query=%s', $video_id);    
             
            // uruchomienie biblioteki curl z adresem url wyszukiwania 
            $handle = curl_init($search_page_url);   
            
            // zaladowanie ustawien biblioteki curl ze stalej z ustawieniami
            curl_setopt_array($handle, self::curl_config);
            
            // wykonanie zapytania HTTP oraz pobranie danych zwrotnych do zmiennej
            $remote_service_response = curl_exec($handle); 
          
            // wyszukiwanie w zwroconych danych informacji o animowanej miniaturze filmu
            preg_match('#"movingThumbnailDetails":{"thumbnails":\[{"url":"([^"]+)?","width":320,"height":180#', $remote_service_response, $matches);
            
            // jezeli nie znaleziono w odpowiedzi serwera danych o miniaturze, zwroc nic
            if(empty($matches[1])) {
               
                // zamkniecie biblioteki curl
                curl_close($handle);
                return false;
            }             
          
            // "hakerski sposob" na zbudowanie obiektu JSON ze wczesniej wyszukiwanych danych (ktore rowniez sa obiektem JSON, tylko ogromnym) oraz przetworzenie go do tablicy
            $json_array_template = json_decode('{"url":"'.$matches[1].'"}', true);
            
            // sprawdzenie czy proba zdekodowania obiektu JSON sie powiodla
            $thumbnail_url = isset($json_array_template['url']) ? $json_array_template['url'] : false;
            
            // jezeli nie, zwroc nic
            if(empty($thumbnail_url)) {
                
                // zamkniecie biblioteki curl
                curl_close($handle);
                return false;
            }
            
            // zmiana adresu url w bibliotece url na adres animowanej miniatury
            curl_setopt($handle, CURLOPT_URL, $thumbnail_url);     
            
            // wykonanie zapytania HTTP w celu pobrania tresci pliku z miniatura, uzywajac plikow cookie z poprzedniego requestu        
            $animated_thumbnail_data = curl_exec($handle); 
            
            // zamkniecie biblioteki curl
            curl_close($handle);
            
            // zwrocenie danych w formie zawartosci pliku .webp z animowana miniatura
            return !empty($animated_thumbnail_data) ? $animated_thumbnail_data : false;
        } 
}

?>