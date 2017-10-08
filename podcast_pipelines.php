<?php

function podcast_post_syndication($flux) {
	
	include_spip("inc/filtres");
	$enclosures = extraire_balises($flux["data"]["enclosures"],"a");
	
	$date =  date("Y-m-d h:i:s",$flux["data"]["date"]);
		if(!$date)
			$date =  date("Y-m-d h:i:s");

	if(is_array($enclosures) AND sizeof($enclosures) > 0){
		foreach($enclosures as $link){
			if(extraire_attribut($link,'type') == "audio/mpeg" OR extraire_attribut($link,'type') == "audio/mp3")
				$liens[] = extraire_attribut($link,'href') ;
		}
	}
	

	if(is_array($liens) AND sizeof($liens) > 0){
		inserer_document_syndic_article($liens, $flux['args']['id_objet'], $date,$flux["data"]["titre"]);	 

  	}else{
		include_spip("inc/utils");	
		 // lancer une tache cron de scan
	 	 $id_job = job_queue_add('radiobot_scan', "Scan de " . $flux['args']['id_objet'] . " : " . $flux["data"]["titre"], $arguments = array($flux['args']['id_objet'], $flux["data"]["titre"],$flux['data']['url'], $date),'podcast_pipelines', $no_duplicate = FALSE, strtotime("+10 seconds"), $priority=0); 

	}

    return $flux;
}

function radiobot_scan($id_syndic_article, $titre_parent = "Sans titre", $url, $date){

	include_spip('inc/distant');
	$page_distante = recuperer_page($url);
	
	//chercher un mp3 avec une url absolue
	preg_match_all(",http://[a-zA-Z0-9\s()\/\:\._%\?+'=~-]*\.mp3,Uims",$page_distante,$matches,PREG_SET_ORDER);
	
	if (count($matches)){
		foreach ($matches as $m){
			// virer ici les faux mp3
			if(!preg_match(",^http://twitter.com/intent/tweet,",$m[0]))
				$enclosures[] = $m[0];
		}
	}else{ // chercher un lien relatif
		//chercher un mp3
		preg_match_all(",(href|src)=(\"|')([a-zA-Z0-9\s()\/\:\._%\?+'=~-]*\.mp3),Uims",$page_distante,$matches,PREG_SET_ORDER);
		foreach ($matches as $m){
			$parse_url = parse_url($url);
			$enclosures[] = "http://" . $parse_url['host'] . $m[3];
		}

	}

	if(is_array($enclosures) and sizeof($enclosures) > 0){
		$enclosures = array_unique($enclosures);
		if(!$date)
			$date =  date("Y-m-d h:i:s");
		inserer_document_syndic_article($enclosures,$id_syndic_article,$date,$titre_parent);
	}

}

function inserer_document_syndic_article($liens, $id_syndic_article, $date, $titre_parent){
	
	include_spip("base/abstract_sql");

	$id_article_syndic = sql_getfetsel("id_syndic_article", "spip_syndic_articles",
		"id_syndic_article="  . _q($id_syndic_article), "", "date desc","0,1");


	if(!$date)
		$date =  date("Y-m-d h:i:s");

		foreach($liens as $link){	
			
			$id3 = recuperer_id3($link) ;
			
			$champs = array(
				'titre' =>  $titre_parent,
				'fichier' => $link,
				'tag_auteur' => $id3['artiste'],
				'tag_titre' => $id3['titre'],				
				'date' => date("Y-m-d H:i:s", $date),
				'distant' => 'oui',
				'statut' => 'publie',
				'date' => $date,
				'extension' => 'mp3'
			);

		  	$s = sql_getfetsel("id_document", "spip_documents",
				"fichier="  . _q($champs['fichier']), "", "date desc","0,1");
		

			if($s){
				// maj le document distant
		    	sql_updateq('spip_documents', $champs, 'id_document=' . intval($s));	
				// a t'on un lien entre ce doc et cet article ?
				$l = sql_getfetsel("id_document", "spip_documents_liens", "id_document="  . _q($s) . " and id_objet="  . _q($id_article_syndic));
				if(!$l){
					$champs_liens = array(
					'id_document' => $s,
					'id_objet' => $id_article_syndic,
					'objet' => 'syndic_article'
					);
					sql_insertq('spip_documents_liens' , $champs_liens);
				}

		    }else{
				// enregistrer le document distant
				$id_document = sql_insertq('spip_documents' , $champs);
				// le lier a son syndic article
				$champs_liens = array(
					'id_document' => $id_document,
					'id_objet' => $id_article_syndic,
					'objet' => 'syndic_article'
				);
				sql_insertq('spip_documents_liens' , $champs_liens);
			}
			   
		}

}


function recuperer_id3($fichier){
	// Copy remote file locally to scan with getID3()
	require_once(find_in_path('/getid3/getid3.php'));
	$getID3 = new getID3;	
	$remotefilename = $fichier ;
	if ($fp_remote = @fopen($remotefilename, 'rb')) {
	    $localtempfilename = tempnam('tmp', 'getID3');
	    if ($fp_local = @fopen($localtempfilename, 'wb')) {
	        // Do this to copy the entire file:
	        //while ($buffer = fread($fp_remote, 16384)) {
	        //    fwrite($fp_local, $buffer);
	        //}
	        
	        // Do this to only work on the first 10kB of the file (good enough for most formats)
	        $buffer = fread($fp_remote, 10240);
	        fwrite($fp_local, $buffer);
	        
	        fclose($fp_local);
	        
	        // Scan file - should parse correctly if file is not corrupted
	        $ThisFileInfo = $getID3->analyze($localtempfilename);
	        // re-scan file more aggressively if file is corrupted somehow and first scan did not correctly identify
	        /*if (empty($ThisFileInfo['fileformat']) || ($ThisFileInfo['fileformat'] == 'id3')) {
	            $ThisFileInfo = GetAllFileInfo($localtempfilename, strtolower(fileextension($localtempfilename)));
	        }*/
	        
	        // Delete temporary file
	        unlink($localtempfilename);
	    }
	    fclose($fp_remote);
	}
	
	if(sizeof($ThisFileInfo)>0){
	
			$id3['titre'] = ($ThisFileInfo['tags']['id3v2']['title']['0']) ? $ThisFileInfo['tags']['id3v2']['title']['0'] : $ThisFileInfo['id3v2']['comments']['title']['0'] ;
			$id3['artiste'] = ($ThisFileInfo['tags']['id3v2']['artist']['0']) ? $ThisFileInfo['tags']['id3v2']['artist']['0'] : $ThisFileInfo['id3v2']['comments']['artist']['0'] ;
			$id3['album']  = ($ThisFileInfo['tags']['id3v2']['album']['0']) ? $ThisFileInfo['tags']['id3v2']['album']['0'] : $ThisFileInfo['id3v2']['comments']['album']['0'] ;
			$id3['genre'] = ($ThisFileInfo['tags']['id3v2']['genre']['0']) ? $ThisFileInfo['tags']['id3v2']['genre']['0'] : $ThisFileInfo['id3v2']['comments']['genre']['0'] ;
			$id3['comment'] = ($ThisFileInfo['tags']['id3v2']['comment']['0']) ? $ThisFileInfo['tags']['id3v2']['comment']['0'] : $ThisFileInfo['id3v2']['comments']['comment']['0'] ;
			$id3['sample_rate'] = $ThisFileInfo['audio']['sample_rate'] ;
			$id3['track'] = $ThisFileInfo['tags']['id3v2']['track']['0'] ;
			$id3['encoded_by'] = $ThisFileInfo['tags']['id3v2']['encoded_by']['0'] ;
			$id3['totaltracks'] = $ThisFileInfo['tags']['id3v2']['totaltracks']['0'] ;
			$id3['tracknum'] = $ThisFileInfo['tags']['id3v2']['totaltracks']['0'] ;
			
		
			return $id3 ;
			
	}	
}

?>