<?php

class getid3_quicktime extends getid3_handler
{

    public function Analyze() {

        $getid3 = $this->getid3;

        $info   = &$getid3->info;

        $getid3->include_module('audio.mp3');

        $info['quicktime'] = array ();
        $info_quicktime = &$info['quicktime'];

        $info['fileformat'] = 'quicktime';
        $info_quicktime['hinting'] = false;

        fseek($getid3->fp, $info['avdataoffset'], SEEK_SET);

        $offset = $atom_counter = 0;

        while ($offset < $info['avdataend']) {

            fseek($getid3->fp, $offset, SEEK_SET);
            $atom_header = fread($getid3->fp, 8);

            $atom_size = getid3_lib::BigEndian2Int(substr($atom_header, 0, 4));
            $atom_name =               substr($atom_header, 4, 4);

            $info_quicktime[$atom_name]['name']   = $atom_name;
            $info_quicktime[$atom_name]['size']   = $atom_size;
            $info_quicktime[$atom_name]['offset'] = $offset;

            if (($offset + $atom_size) > $info['avdataend']) {
                throw new getid3_exception('Atom at offset '.$offset.' claims to go beyond end-of-file (length: '.$atom_size.' bytes)');
            }

            if ($atom_size == 0) {
                // Furthermore, for historical reasons the list of atoms is optionally
                // terminated by a 32-bit integer set to 0. If you are writing a program
                // to read user data atoms, you should allow for the terminating 0.
                break;
            }

            switch ($atom_name) {

                case 'mdat': // Media DATa atom
                    // 'mdat' contains the actual data for the audio/video
                    if (($atom_size > 8) && (!isset($info['avdataend_tmp']) || ($info_quicktime[$atom_name]['size'] > ($info['avdataend_tmp'] - $info['avdataoffset'])))) {

                        $info['avdataoffset'] = $info_quicktime[$atom_name]['offset'] + 8;
                        $old_av_data_end      = $info['avdataend'];
                        $info['avdataend']    = $info_quicktime[$atom_name]['offset'] + $info_quicktime[$atom_name]['size'];


                        //// MP3

                        if (!$getid3->include_module_optional('audio.mp3')) {
                           $getid3->warning('MP3 skipped because mpeg module is missing.');
                        }

                        else {

                            // Clone getid3 - messing with offsets - better safe than sorry
                            $clone = clone $getid3;

                            if (getid3_mp3::MPEGaudioHeaderValid(getid3_mp3::MPEGaudioHeaderDecode(fread($clone->fp, 4)))) {

                                $mp3 = new getid3_mp3($clone);
                                $mp3->AnalyzeMPEGaudioInfo();

                                // Import from clone and destroy
                                if (isset($clone->info['mpeg']['audio'])) {

                                    $info['mpeg']['audio'] = $clone->info['mpeg']['audio'];

                                    $info['audio']['dataformat']   = 'mp3';
                                    $info['audio']['codec']        = (!empty($info['mpeg']['audio']['encoder']) ? $info['mpeg']['audio']['encoder'] : (!empty($info['mpeg']['audio']['codec']) ? $info['mpeg']['audio']['codec'] : (!empty($info['mpeg']['audio']['LAME']) ? 'LAME' :'mp3')));
                                    $info['audio']['sample_rate']  = $info['mpeg']['audio']['sample_rate'];
                                    $info['audio']['channels']     = $info['mpeg']['audio']['channels'];
                                    $info['audio']['bitrate']      = $info['mpeg']['audio']['bitrate'];
                                    $info['audio']['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
                                    $info['bitrate']               = $info['audio']['bitrate'];

                                    $getid3->warning($clone->warnings());
                                    unset($clone);
                                }
                            }
                        }

                        $info['avdataend'] = $old_av_data_end;
                        unset($old_av_data_end);

                    }
                    break;


                case 'free': // FREE space atom
                case 'skip': // SKIP atom
                case 'wide': // 64-bit expansion placeholder atom
                    // 'free', 'skip' and 'wide' are just padding, contains no useful data at all
                    break;


                default:
                    $atom_hierarchy = array ();
                    $info_quicktime[$atom_name] = $this->QuicktimeParseAtom($atom_name, $atom_size, fread($getid3->fp, $atom_size), $offset, $atom_hierarchy);
                    break;
            }

            $offset += $atom_size;
            $atom_counter++;
        }

        if (!empty($info['avdataend_tmp'])) {
            // this value is assigned to a temp value and then erased because
            // otherwise any atoms beyond the 'mdat' atom would not get parsed
            $info['avdataend'] = $info['avdataend_tmp'];
            unset($info['avdataend_tmp']);
        }

        if (!isset($info['bitrate']) && isset($info['playtime_seconds'])) {
            $info['bitrate'] = (($info['avdataend'] - $info['avdataoffset']) * 8) / $info['playtime_seconds'];
        }

        if (isset($info['bitrate']) && !isset($info['audio']['bitrate']) && !isset($info_quicktime['video'])) {
            $info['audio']['bitrate'] = $info['bitrate'];
        }

		if (@$info['playtime_seconds'] && !isset($info['video']['frame_rate']) && !empty($info['quicktime']['stts_framecount'])) {
			foreach ($info['quicktime']['stts_framecount'] as $key => $samples_count) {
				$samples_per_second = $samples_count / $info['playtime_seconds'];
				if ($samples_per_second > 240) {
					// has to be audio samples
				} else {
					$info['video']['frame_rate'] = $samples_per_second;
					break;
				}
			}
		}

        if ((@$info['audio']['dataformat'] == 'mp4') && empty($info['video']['resolution_x'])) {
            $info['fileformat'] = 'mp4';
            $info['mime_type']  = 'audio/mp4';
            unset($info['video']['dataformat']);
        }

        if (!$getid3->option_extra_info) {
            unset($info_quicktime['moov']);
        }

        if (empty($info['audio']['dataformat']) && !empty($info_quicktime['audio'])) {
            $info['audio']['dataformat'] = 'quicktime';
        }

        if (empty($info['video']['dataformat']) && !empty($info_quicktime['video'])) {
            $info['video']['dataformat'] = 'quicktime';
        }

        return true;
    }



    private function QuicktimeParseAtom($atom_name, $atom_size, $atom_data, $base_offset, &$atom_hierarchy) {

        // http://developer.apple.com/techpubs/quicktime/qtdevdocs/APIREF/INDEX/atomalphaindex.htm

        $getid3 = $this->getid3;

        $info           = &$getid3->info;
        $info_quicktime = &$info['quicktime'];

        array_push($atom_hierarchy, $atom_name);
        $atom_structure['hierarchy'] = implode(' ', $atom_hierarchy);
        $atom_structure['name']      = $atom_name;
        $atom_structure['size']      = $atom_size;
        $atom_structure['offset']    = $base_offset;

        switch ($atom_name) {
            case 'moov': // MOVie container atom
            case 'trak': // TRAcK container atom
            case 'clip': // CLIPping container atom
            case 'matt': // track MATTe container atom
            case 'edts': // EDiTS container atom
            case 'tref': // Track REFerence container atom
            case 'mdia': // MeDIA container atom
            case 'minf': // Media INFormation container atom
            case 'dinf': // Data INFormation container atom
            case 'udta': // User DaTA container atom
            case 'stbl': // Sample TaBLe container atom
            case 'cmov': // Compressed MOVie container atom
            case 'rmra': // Reference Movie Record Atom
            case 'rmda': // Reference Movie Descriptor Atom
            case 'gmhd': // Generic Media info HeaDer atom (seen on QTVR)
                $atom_structure['subatoms'] = $this->QuicktimeParseContainerAtom($atom_data, $base_offset + 8, $atom_hierarchy);
                break;


			case 'aART':
			case 'catg':
			case 'covr':
			case 'cpil':
			case 'cprt':
			case 'desc':
			case 'disk':
			case 'egid':
			case 'gnre':
			case 'keyw':
			case 'pcst':
			case 'pgap':
			case 'purd':
			case 'purl':
			case 'rtng':
			case 'stik':
			case 'tmpo':
			case 'trkn':
			case 'tves':
			case 'tvnn':
			case 'tvsh':
			case 'tvsn':
			case '©alb':
			case '©ART':
			case '©aut':
			case '©cmt':
			case '©com':
			case '©cpy':
			case '©day':
			case '©dir':
			case '©ed1':
			case '©ed2':
			case '©ed3':
			case '©ed4':
			case '©ed5':
			case '©ed6':
			case '©ed7':
			case '©ed8':
			case '©ed9':
			case '©enc':
			case '©fmt':
			case '©gen':
			case '©grp':
			case '©hst':
			case '©inf':
			case '©lyr':
			case '©mak':
			case '©mod':
			case '©nam':
			case '©ope':
			case '©PRD':
			case '©prf':
			case '©req':
			case '©src':
			case '©swr':
			case '©too':
			case '©trk':
			case '©url':
			case '©wrn':
			case '©wrt':
                $atom_structure['data_length'] = getid3_lib::BigEndian2Int(substr($atom_data,  0, 2));
				$atom_structure['language_id'] = getid3_lib::BigEndian2Int(substr($atom_data,  2, 2));
				$atom_structure['data']        =                           substr($atom_data,  4);

                $atom_structure['language']    = $this->QuicktimeLanguageLookup($atom_structure['language_id']);
                if (empty($info['comments']['language']) || (!in_array($atom_structure['language'], $info['comments']['language']))) {
                    $info['comments']['language'][] = $atom_structure['language'];
                }
                $this->CopyToAppropriateCommentsSection($atom_name, $atom_structure['data']);
                break;


            case 'play': // auto-PLAY atom
                $atom_structure['autoplay'] = (bool)getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));

                $info_quicktime['autoplay'] = $atom_structure['autoplay'];
                break;


            case 'WLOC': // Window LOCation atom
                $atom_structure['location_x'] = getid3_lib::BigEndian2Int(substr($atom_data,  0, 2));
                $atom_structure['location_y'] = getid3_lib::BigEndian2Int(substr($atom_data,  2, 2));
                break;


            case 'LOOP': // LOOPing atom
            case 'SelO': // play SELection Only atom
            case 'AllF': // play ALL Frames atom
                $atom_structure['data'] = getid3_lib::BigEndian2Int($atom_data);
                break;


            case 'name': //
            case 'MCPS': // Media Cleaner PRo
            case '@PRM': // adobe PReMiere version
            case '@PRQ': // adobe PRemiere Quicktime version
                $atom_structure['data'] = $atom_data;
                break;


            case 'cmvd': // Compressed MooV Data atom
                // Code by ubergeekØubergeek*tv based on information from
                // http://developer.apple.com/quicktime/icefloe/dispatch012.html
                $atom_structure['unCompressedSize'] = getid3_lib::BigEndian2Int(substr($atom_data, 0, 4));

                $compressed_file_data = substr($atom_data, 4);
                if (!function_exists('gzuncompress'))  {
                    $getid3->warning('PHP does not have zlib support - cannot decompress MOV atom at offset '.$atom_structure['offset']);
                }
                elseif ($uncompressed_header = @gzuncompress($compressed_file_data)) {
                    $atom_structure['subatoms'] = $this->QuicktimeParseContainerAtom($uncompressed_header, 0, $atom_hierarchy);
                } else {
                    $getid3->warning('Error decompressing compressed MOV atom at offset '.$atom_structure['offset']);
                }
                break;


            case 'dcom': // Data COMpression atom
                $atom_structure['compression_id']   = $atom_data;
                $atom_structure['compression_text'] = getid3_quicktime::QuicktimeDCOMLookup($atom_data);
                break;


            case 'rdrf': // Reference movie Data ReFerence atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'             => 1,
                        'flags_raw'           => 3,
                        'reference_type_name' => -4,
                        'reference_length'    => 4,
                    )
                );

                $atom_structure['flags']['internal_data'] = (bool)($atom_structure['flags_raw'] & 0x000001);

                switch ($atom_structure['reference_type_name']) {
                    case 'url ':
                        $atom_structure['url']            = $this->NoNullString(substr($atom_data, 12));
                        break;

                    case 'alis':
                        $atom_structure['file_alias']     =                     substr($atom_data, 12);
                        break;

                    case 'rsrc':
                        $atom_structure['resource_alias'] =                     substr($atom_data, 12);
                        break;

                    default:
                        $atom_structure['data']           =                     substr($atom_data, 12);
                        break;
                }
                break;


            case 'rmqu': // Reference Movie QUality atom
                $atom_structure['movie_quality'] = getid3_lib::BigEndian2Int($atom_data);
                break;


            case 'rmcs': // Reference Movie Cpu Speed atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'          => 1,
                        'flags_raw'        => 3, // hardcoded: 0x0000
                        'cpu_speed_rating' => 2
                    )
                );
                break;


            case 'rmvc': // Reference Movie Version Check atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'            => 1,
                        'flags_raw'          => 3, // hardcoded: 0x0000
                        'gestalt_selector'   => -4,
                        'gestalt_value_mask' => 4,
                        'gestalt_value'      => 4,
                        'gestalt_check_type' => 2
                    )
                );
                break;


            case 'rmcd': // Reference Movie Component check atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'                => 1,
                        'flags_raw'              => 3, // hardcoded: 0x0000
                        'component_type'         => -4,
                        'component_subtype'      => -4,
                        'component_manufacturer' => -4,
                        'component_flags_raw'    => 4,
                        'component_flags_mask'   => 4,
                        'component_min_version'  => 4
                    )
                );
                break;


            case 'rmdr': // Reference Movie Data Rate atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'   => 1,
                        'flags_raw' => 3, // hardcoded: 0x0000
                        'data_rate' => 4
                    )
                );

                $atom_structure['data_rate_bps'] = $atom_structure['data_rate'] * 10;
                break;


            case 'rmla': // Reference Movie Language Atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'     => 1,
                        'flags_raw'   => 3, // hardcoded: 0x0000
                        'language_id' => 2
                    )
                );

                $atom_structure['language']    = $this->QuicktimeLanguageLookup($atom_structure['language_id']);
                if (empty($info['comments']['language']) || (!in_array($atom_structure['language'], $info['comments']['language']))) {
                    $info['comments']['language'][] = $atom_structure['language'];
                }
                break;


            case 'rmla': // Reference Movie Language Atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'   => 1,
                        'flags_raw' => 3, // hardcoded: 0x0000
                        'track_id'  => 2
                    )
                );
                break;


            case 'ptv ': // Print To Video - defines a movie's full screen mode
                // http://developer.apple.com/documentation/QuickTime/APIREF/SOURCESIV/at_ptv-_pg.htm
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'display_size_raw'  => 2,
                        'reserved_1'        => 2, // hardcoded: 0x0000
                        'reserved_2'        => 2, // hardcoded: 0x0000
                        'slide_show_flag'   => 1,
                        'play_on_open_flag' => 1
                    )
                );

                $atom_structure['flags']['play_on_open'] = (bool)$atom_structure['play_on_open_flag'];
                $atom_structure['flags']['slide_show']   = (bool)$atom_structure['slide_show_flag'];

                $ptv_lookup[0] = 'normal';
                $ptv_lookup[1] = 'double';
                $ptv_lookup[2] = 'half';
                $ptv_lookup[3] = 'full';
                $ptv_lookup[4] = 'current';
                if (isset($ptv_lookup[$atom_structure['display_size_raw']])) {
                    $atom_structure['display_size'] = $ptv_lookup[$atom_structure['display_size_raw']];
                } else {
                    $getid3->warning('unknown "ptv " display constant ('.$atom_structure['display_size_raw'].')');
                }
                break;


            case 'stsd': // Sample Table Sample Description atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'        => 1,
                        'flags_raw'      => 3, // hardcoded: 0x0000
                        'number_entries' => 4
                    )
                );
                $stsd_entries_data_offset = 8;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {

                    getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['sample_description_table'][$i], $atom_data, $stsd_entries_data_offset,
                        array (
                            'size'            => 4,
                            'data_format'     => -4,
                            'reserved'        => 6,
                            'reference_index' => 2
                        )
                    );

                    $atom_structure['sample_description_table'][$i]['data'] = substr($atom_data, 16+$stsd_entries_data_offset, ($atom_structure['sample_description_table'][$i]['size'] - 4 - 4 - 6 - 2));
                    $stsd_entries_data_offset += 16 + ($atom_structure['sample_description_table'][$i]['size'] - 4 - 4 - 6 - 2);

                    getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['sample_description_table'][$i], $atom_structure['sample_description_table'][$i]['data'], 0,
                        array (
                            'encoder_version'  => 2,
                            'encoder_revision' => 2,
                            'encoder_vendor'   => -4
                        )
                    );

                    switch ($atom_structure['sample_description_table'][$i]['encoder_vendor']) {

                        case "\x00\x00\x00\x00":
                            // audio atom
                            getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['sample_description_table'][$i], $atom_structure['sample_description_table'][$i]['data'], 8,
                                array (
                                    'audio_channels'       => 2,
                                    'audio_bit_depth'      => 2,
                                    'audio_compression_id' => 2,
                                    'audio_packet_size'    => 2
                                )
                            );

                            $atom_structure['sample_description_table'][$i]['audio_sample_rate'] = getid3_quicktime::FixedPoint16_16(substr($atom_structure['sample_description_table'][$i]['data'], 16, 4));

                            switch ($atom_structure['sample_description_table'][$i]['data_format']) {

                                case 'mp4v':
                                    $info['fileformat'] = 'mp4';
                                    throw new getid3_exception('This version of getID3() does not fully support MPEG-4 audio/video streams');

                                case 'qtvr':
                                    $info['video']['dataformat'] = 'quicktimevr';
                                    break;

                                case 'mp4a':
                                default:
                                    $info_quicktime['audio']['codec']       = $this->QuicktimeAudioCodecLookup($atom_structure['sample_description_table'][$i]['data_format']);
                                    $info_quicktime['audio']['sample_rate'] = $atom_structure['sample_description_table'][$i]['audio_sample_rate'];
                                    $info_quicktime['audio']['channels']    = $atom_structure['sample_description_table'][$i]['audio_channels'];
                                    $info_quicktime['audio']['bit_depth']   = $atom_structure['sample_description_table'][$i]['audio_bit_depth'];
                                    $info['audio']['codec']                 = $info_quicktime['audio']['codec'];
                                    $info['audio']['sample_rate']           = $info_quicktime['audio']['sample_rate'];
                                    $info['audio']['channels']              = $info_quicktime['audio']['channels'];
                                    $info['audio']['bits_per_sample']       = $info_quicktime['audio']['bit_depth'];
                                    switch ($atom_structure['sample_description_table'][$i]['data_format']) {
                                        case 'raw ': // PCM
                                        case 'alac': // Apple Lossless Audio Codec
                                            $info['audio']['lossless'] = true;
                                            break;
                                        default:
                                            $info['audio']['lossless'] = false;
                                            break;
                                    }
                                    break;
                            }
                            break;

                        default:
                            switch ($atom_structure['sample_description_table'][$i]['data_format']) {
                                case 'mp4s':
                                    $info['fileformat'] = 'mp4';
                                    break;

                                default:
                                    // video atom
                                    getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['sample_description_table'][$i], $atom_structure['sample_description_table'][$i]['data'], 8,
                                        array (
                                            'video_temporal_quality' => 4,
                                            'video_spatial_quality'  => 4,
                                            'video_frame_width'      => 2,
                                            'video_frame_height'     => 2
                                        )
                                    );
                                    $atom_structure['sample_description_table'][$i]['video_resolution_x']      = getid3_quicktime::FixedPoint16_16(substr($atom_structure['sample_description_table'][$i]['data'], 20,  4));
                                    $atom_structure['sample_description_table'][$i]['video_resolution_y']      = getid3_quicktime::FixedPoint16_16(substr($atom_structure['sample_description_table'][$i]['data'], 24,  4));
                                    getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['sample_description_table'][$i], $atom_structure['sample_description_table'][$i]['data'], 28,
                                        array (
                                            'video_data_size'        => 4,
                                            'video_frame_count'      => 2,
                                            'video_encoder_name_len' => 1
                                        )
                                    );
                                    $atom_structure['sample_description_table'][$i]['video_encoder_name']      = substr($atom_structure['sample_description_table'][$i]['data'], 35, $atom_structure['sample_description_table'][$i]['video_encoder_name_len']);
                                    $atom_structure['sample_description_table'][$i]['video_pixel_color_depth'] = getid3_lib::BigEndian2Int(substr($atom_structure['sample_description_table'][$i]['data'], 66,  2));
                                    $atom_structure['sample_description_table'][$i]['video_color_table_id']    = getid3_lib::BigEndian2Int(substr($atom_structure['sample_description_table'][$i]['data'], 68,  2));

                                    $atom_structure['sample_description_table'][$i]['video_pixel_color_type']  = (($atom_structure['sample_description_table'][$i]['video_pixel_color_depth'] > 32) ? 'grayscale' : 'color');
                                    $atom_structure['sample_description_table'][$i]['video_pixel_color_name']  = $this->QuicktimeColorNameLookup($atom_structure['sample_description_table'][$i]['video_pixel_color_depth']);

                                    if ($atom_structure['sample_description_table'][$i]['video_pixel_color_name'] != 'invalid') {
                                        $info_quicktime['video']['codec_fourcc']        = $atom_structure['sample_description_table'][$i]['data_format'];
                                        $info_quicktime['video']['codec_fourcc_lookup'] = $this->QuicktimeVideoCodecLookup($atom_structure['sample_description_table'][$i]['data_format']);
                                        $info_quicktime['video']['codec']               = $atom_structure['sample_description_table'][$i]['video_encoder_name'];
                                        $info_quicktime['video']['color_depth']         = $atom_structure['sample_description_table'][$i]['video_pixel_color_depth'];
                                        $info_quicktime['video']['color_depth_name']    = $atom_structure['sample_description_table'][$i]['video_pixel_color_name'];

                                        $info['video']['codec']           = $info_quicktime['video']['codec'];
                                        $info['video']['bits_per_sample'] = $info_quicktime['video']['color_depth'];
                                    }
                                    $info['video']['lossless']           = false;
                                    $info['video']['pixel_aspect_ratio'] = (float)1;
                                    break;
                            }
                            break;
                    }
                    switch (strtolower($atom_structure['sample_description_table'][$i]['data_format'])) {
                        case 'mp4a':
                            $info['audio']['dataformat'] = $info_quicktime['audio']['codec'] = 'mp4';
                            break;

                        case '3ivx':
                        case '3iv1':
                        case '3iv2':
                            $info['video']['dataformat'] = '3ivx';
                            break;

                        case 'xvid':
                            $info['video']['dataformat'] = 'xvid';
                            break;

                        case 'mp4v':
                            $info['video']['dataformat'] = 'mpeg4';
                            break;

                        case 'divx':
                        case 'div1':
                        case 'div2':
                        case 'div3':
                        case 'div4':
                        case 'div5':
                        case 'div6':
                            //$TDIVXileInfo['video']['dataformat'] = 'divx';
                            break;

                        default:
                            // do nothing
                            break;
                    }
                    unset($atom_structure['sample_description_table'][$i]['data']);
                }
                break;


            case 'stts': // Sample Table Time-to-Sample atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'        => 1,
                        'flags_raw'      => 3, // hardcoded: 0x0000
                        'number_entries' => 4
                    )
                );

                $stts_entries_data_offset = 8;
                //$frame_rate_calculator_array = array ();
				$frames_count = 0;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {

                    $atom_structure['time_to_sample_table'][$i]['sample_count']    = getid3_lib::BigEndian2Int(substr($atom_data, $stts_entries_data_offset, 4));
                    $stts_entries_data_offset += 4;

                    $atom_structure['time_to_sample_table'][$i]['sample_duration'] = getid3_lib::BigEndian2Int(substr($atom_data, $stts_entries_data_offset, 4));
                    $stts_entries_data_offset += 4;

                    $frames_count += $atom_structure['time_to_sample_table'][$i]['sample_count'];

                    //if (!empty($info_quicktime['time_scale']) && (@$atoms_structure['time_to_sample_table'][$i]['sample_duration'] > 0)) {
                    //
                    //    $stts_new_framerate = $info_quicktime['time_scale'] / $atom_structure['time_to_sample_table'][$i]['sample_duration'];
                    //    if ($stts_new_framerate <= 60) {
                    //        // some atoms have durations of "1" giving a very large framerate, which probably is not right
                    //        $info['video']['frame_rate'] = max(@$info['video']['frame_rate'], $stts_new_framerate);
                    //    }
                    //}
                    //@$frame_rate_calculator_array[($info_quicktime['time_scale'] / $atom_structure['time_to_sample_table'][$i]['sample_duration'])] += $atom_structure['time_to_sample_table'][$i]['sample_count'];
                }
                $info['quicktime']['stts_framecount'][] = $frames_count;
                break;


            case 'stss': // Sample Table Sync Sample (key frames) atom
                /*
                $atom_structure['version']        = getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));
                $atom_structure['flags_raw']      = getid3_lib::BigEndian2Int(substr($atom_data,  1, 3)); // hardcoded: 0x0000
                $atom_structure['number_entries'] = getid3_lib::BigEndian2Int(substr($atom_data,  4, 4));
                $stss_entries_data_offset = 8;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {
                    $atom_structure['time_to_sample_table'][$i] = getid3_lib::BigEndian2Int(substr($atom_data, $stss_entries_data_offset, 4));
                    $stss_entries_data_offset += 4;
                }
                */
                break;


            case 'stsc': // Sample Table Sample-to-Chunk atom
                /*
                $atom_structure['version']        = getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));
                $atom_structure['flags_raw']      = getid3_lib::BigEndian2Int(substr($atom_data,  1, 3)); // hardcoded: 0x0000
                $atom_structure['number_entries'] = getid3_lib::BigEndian2Int(substr($atom_data,  4, 4));
                $stsc_entries_data_offset = 8;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {
                    $atom_structure['sample_to_chunk_table'][$i]['first_chunk']        = getid3_lib::BigEndian2Int(substr($atom_data, $stsc_entries_data_offset, 4));
                    $stsc_entries_data_offset += 4;
                    $atom_structure['sample_to_chunk_table'][$i]['samples_per_chunk']  = getid3_lib::BigEndian2Int(substr($atom_data, $stsc_entries_data_offset, 4));
                    $stsc_entries_data_offset += 4;
                    $atom_structure['sample_to_chunk_table'][$i]['sample_description'] = getid3_lib::BigEndian2Int(substr($atom_data, $stsc_entries_data_offset, 4));
                    $stsc_entries_data_offset += 4;
                }
                */
                break;


            case 'stsz': // Sample Table SiZe atom
                /*
                $atom_structure['version']        = getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));
                $atom_structure['flags_raw']      = getid3_lib::BigEndian2Int(substr($atom_data,  1, 3)); // hardcoded: 0x0000
                $atom_structure['sample_size']    = getid3_lib::BigEndian2Int(substr($atom_data,  4, 4));
                $atom_structure['number_entries'] = getid3_lib::BigEndian2Int(substr($atom_data,  8, 4));
                $stsz_entries_data_offset = 12;
                if ($atom_structure['sample_size'] == 0) {
                    for ($i = 0; $i < $atom_structure['number_entries']; $i++) {
                        $atom_structure['sample_size_table'][$i] = getid3_lib::BigEndian2Int(substr($atom_data, $stsz_entries_data_offset, 4));
                        $stsz_entries_data_offset += 4;
                    }
                }
                */
                break;


            case 'stco': // Sample Table Chunk Offset atom
                /*
                $atom_structure['version']        = getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));
                $atom_structure['flags_raw']      = getid3_lib::BigEndian2Int(substr($atom_data,  1, 3)); // hardcoded: 0x0000
                $atom_structure['number_entries'] = getid3_lib::BigEndian2Int(substr($atom_data,  4, 4));
                $stco_entries_data_offset = 8;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {
                    $atom_structure['chunk_offset_table'][$i] = getid3_lib::BigEndian2Int(substr($atom_data, $stco_entries_data_offset, 4));
                    $stco_entries_data_offset += 4;
                }
                */
                break;


            case 'dref': // Data REFerence atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'        => 1,
                        'flags_raw'      => 3, // hardcoded: 0x0000
                        'number_entries' => 4
                    )
                );

                $dref_data_offset = 8;
                for ($i = 0; $i < $atom_structure['number_entries']; $i++) {

                    getid3_lib::ReadSequence('BigEndian2Int', $atom_structure['data_references'][$i], $atom_data, $dref_data_offset,
                        array (
                            'size'      => 4,
                            'type'      => -4,
                            'version'   => 1,
                            'flags_raw' => 3  // hardcoded: 0x0000
                        )
                    );
                    $dref_data_offset += 12;

                    $atom_structure['data_references'][$i]['data'] = substr($atom_data, $dref_data_offset, ($atom_structure['data_references'][$i]['size'] - 4 - 4 - 1 - 3));
                    $dref_data_offset += ($atom_structure['data_references'][$i]['size'] - 4 - 4 - 1 - 3);

                    $atom_structure['data_references'][$i]['flags']['self_reference'] = (bool)($atom_structure['data_references'][$i]['flags_raw'] & 0x001);
                }
                break;


            case 'gmin': // base Media INformation atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'       => 1,
                        'flags_raw'     => 3, // hardcoded: 0x0000
                        'graphics_mode' => 2,
                        'opcolor_red'   => 2,
                        'opcolor_green' => 2,
                        'opcolor_blue'  => 2,
                        'balance'       => 2,
                        'reserved'      => 2
                    )
                );
                break;


            case 'smhd': // Sound Media information HeaDer atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'   => 1,
                        'flags_raw' => 3, // hardcoded: 0x0000
                        'balance'   => 2,
                        'reserved'  => 2
                    )
                );
                break;


            case 'vmhd': // Video Media information HeaDer atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'       => 1,
                        'flags_raw'     => 3,
                        'graphics_mode' => 2,
                        'opcolor_red'   => 2,
                        'opcolor_green' => 2,
                        'opcolor_blue'  => 2
                    )
                );
                $atom_structure['flags']['no_lean_ahead'] = (bool)($atom_structure['flags_raw'] & 0x001);
                break;


            case 'hdlr': // HanDLeR reference atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'                => 1,
                        'flags_raw'              => 3, // hardcoded: 0x0000
                        'component_type'         => -4,
                        'component_subtype'      => -4,
                        'component_manufacturer' => -4,
                        'component_flags_raw'    => 4,
                        'component_flags_mask'   => 4
                    )
                );

                $atom_structure['component_name'] = substr(substr($atom_data, 24), 1);       /// Pascal2String

                if (($atom_structure['component_subtype'] == 'STpn') && ($atom_structure['component_manufacturer'] == 'zzzz')) {
                    $info['video']['dataformat'] = 'quicktimevr';
                }
                break;


            case 'mdhd': // MeDia HeaDer atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'       => 1,
                        'flags_raw'     => 3, // hardcoded: 0x0000
                        'creation_time' => 4,
                        'modify_time'   => 4,
                        'time_scale'    => 4,
                        'duration'      => 4,
                        'language_id'   => 2,
                        'quality'       => 2
                    )
                );

                if ($atom_structure['time_scale'] == 0) {
                    throw new getid3_exception('Corrupt Quicktime file: mdhd.time_scale == zero');
                }
                $info_quicktime['time_scale'] = max(@$info['quicktime']['time_scale'], $atom_structure['time_scale']);

                $atom_structure['creation_time_unix'] = (int)($atom_structure['creation_time'] - 2082844800); // DateMac2Unix()
                $atom_structure['modify_time_unix']   = (int)($atom_structure['modify_time']   - 2082844800); // DateMac2Unix()
                $atom_structure['playtime_seconds']   = $atom_structure['duration'] / $atom_structure['time_scale'];
                $atom_structure['language']           = $this->QuicktimeLanguageLookup($atom_structure['language_id']);
                if (empty($info['comments']['language']) || (!in_array($atom_structure['language'], $info['comments']['language']))) {
                    $info['comments']['language'][] = $atom_structure['language'];
                }
                break;


            case 'pnot': // Preview atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'modification_date' => 4,   // "standard Macintosh format"
                        'version_number'    => 2,   // hardcoded: 0x00
                        'atom_type'         => -4,  // usually: 'PICT'
                        'atom_index'        => 2    // usually: 0x01
                    )
                );
                $atom_structure['modification_date_unix'] = (int)($atom_structure['modification_date'] - 2082844800); // DateMac2Unix()
                break;


            case 'crgn': // Clipping ReGioN atom
                $atom_structure['region_size']   = getid3_lib::BigEndian2Int(substr($atom_data,  0, 2)); // The Region size, Region boundary box,
                $atom_structure['boundary_box']  = getid3_lib::BigEndian2Int(substr($atom_data,  2, 8)); // and Clipping region data fields
                $atom_structure['clipping_data'] =                           substr($atom_data, 10);           // constitute a QuickDraw region.
                break;


            case 'load': // track LOAD settings atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'preload_start_time' => 4,
                        'preload_duration'   => 4,
                        'preload_flags_raw'  => 4,
                        'default_hints_raw'  => 4
                    )
                );

                $atom_structure['default_hints']['double_buffer'] = (bool)($atom_structure['default_hints_raw'] & 0x0020);
                $atom_structure['default_hints']['high_quality']  = (bool)($atom_structure['default_hints_raw'] & 0x0100);
                break;


            case 'tmcd': // TiMe CoDe atom
            case 'chap': // CHAPter list atom
            case 'sync': // SYNChronization atom
            case 'scpt': // tranSCriPT atom
            case 'ssrc': // non-primary SouRCe atom
                for ($i = 0; $i < (strlen($atom_data) % 4); $i++) {
                    $atom_structure['track_id'][$i] = getid3_lib::BigEndian2Int(substr($atom_data, $i * 4, 4));
                }
                break;


            case 'elst': // Edit LiST atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'        => 1,
                        'flags_raw'      => 3, // hardcoded: 0x0000
                        'number_entries' => 4
                    )
                );

                for ($i = 0; $i < $atom_structure['number_entries']; $i++ ) {
                    $atom_structure['edit_list'][$i]['track_duration'] = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($i * 12) + 0, 4));
                    $atom_structure['edit_list'][$i]['media_time']     = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($i * 12) + 4, 4));
                    $atom_structure['edit_list'][$i]['media_rate']     = getid3_quicktime::FixedPoint16_16(substr($atom_data, 8 + ($i * 12) + 8, 4));
                }
                break;


            case 'kmat': // compressed MATte atom
                $atom_structure['version']        = getid3_lib::BigEndian2Int(substr($atom_data,  0, 1));
                $atom_structure['flags_raw']      = getid3_lib::BigEndian2Int(substr($atom_data,  1, 3)); // hardcoded: 0x0000
                $atom_structure['matte_data_raw'] =                           substr($atom_data,  4);
                break;


            case 'ctab': // Color TABle atom
                $atom_structure['color_table_seed']   = getid3_lib::BigEndian2Int(substr($atom_data,  0, 4)); // hardcoded: 0x00000000
                $atom_structure['color_table_flags']  = getid3_lib::BigEndian2Int(substr($atom_data,  4, 2)); // hardcoded: 0x8000
                $atom_structure['color_table_size']   = getid3_lib::BigEndian2Int(substr($atom_data,  6, 2)) + 1;
                for ($colortableentry = 0; $colortableentry < $atom_structure['color_table_size']; $colortableentry++) {
                    $atom_structure['color_table'][$colortableentry]['alpha'] = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($colortableentry * 8) + 0, 2));
                    $atom_structure['color_table'][$colortableentry]['red']   = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($colortableentry * 8) + 2, 2));
                    $atom_structure['color_table'][$colortableentry]['green'] = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($colortableentry * 8) + 4, 2));
                    $atom_structure['color_table'][$colortableentry]['blue']  = getid3_lib::BigEndian2Int(substr($atom_data, 8 + ($colortableentry * 8) + 6, 2));
                }
                break;


            case 'mvhd': // MoVie HeaDer atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'       => 1,
                        'flags_raw'     => 3,
                        'creation_time' => 4,
                        'modify_time'   => 4,
                        'time_scale'    => 4,
                        'duration'      => 4
                    )
                );

                $atom_structure['preferred_rate']     = getid3_quicktime::FixedPoint16_16(substr($atom_data, 20, 4));
                $atom_structure['preferred_volume']   =   getid3_quicktime::FixedPoint8_8(substr($atom_data, 24, 2));
                $atom_structure['reserved']           =                                   substr($atom_data, 26, 10);
                $atom_structure['matrix_a']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 36, 4));
                $atom_structure['matrix_b']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 40, 4));
                $atom_structure['matrix_u']           =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 44, 4));
                $atom_structure['matrix_c']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 48, 4));
                $atom_structure['matrix_d']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 52, 4));
                $atom_structure['matrix_v']           =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 56, 4));
                $atom_structure['matrix_x']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 60, 4));
                $atom_structure['matrix_y']           = getid3_quicktime::FixedPoint16_16(substr($atom_data, 64, 4));
                $atom_structure['matrix_w']           =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 68, 4));

                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 72,
                    array (
                        'preview_time'       => 4,
                        'preview_duration'   => 4,
                        'poster_time'        => 4,
                        'selection_time'     => 4,
                        'selection_duration' => 4,
                        'current_time'       => 4,
                        'next_track_id'      => 4
                    )
                );

                if ($atom_structure['time_scale'] == 0) {
                    throw new getid3_exception('Corrupt Quicktime file: mvhd.time_scale == zero');
                }

                $atom_structure['creation_time_unix']        = (int)($atom_structure['creation_time'] - 2082844800); // DateMac2Unix()
                $atom_structure['modify_time_unix']          = (int)($atom_structure['modify_time']   - 2082844800); // DateMac2Unix()
                $info_quicktime['time_scale'] = max(@$info['quicktime']['time_scale'], $atom_structure['time_scale']);
                $info_quicktime['display_scale'] = $atom_structure['matrix_a'];
                $info['playtime_seconds']           = $atom_structure['duration'] / $atom_structure['time_scale'];
                break;


            case 'tkhd': // TracK HeaDer atom
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'version'         => 1,
                        'flags_raw'       => 3,
                        'creation_time'   => 4,
                        'modify_time'     => 4,
                        'trackid'         => 4,
                        'reserved1'       => 4,
                        'duration'        => 4,
                        'reserved2'       => 8,
                        'layer'           => 2,
                        'alternate_group' => 2
                    )
                );

                $atom_structure['volume']              =   getid3_quicktime::FixedPoint8_8(substr($atom_data, 36, 2));
                $atom_structure['reserved3']           =         getid3_lib::BigEndian2Int(substr($atom_data, 38, 2));
                $atom_structure['matrix_a']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 40, 4));
                $atom_structure['matrix_b']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 44, 4));
                $atom_structure['matrix_u']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 48, 4));
                $atom_structure['matrix_c']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 52, 4));
                $atom_structure['matrix_v']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 56, 4));
                $atom_structure['matrix_d']            = getid3_quicktime::FixedPoint16_16(substr($atom_data, 60, 4));
                $atom_structure['matrix_x']            =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 64, 4));
                $atom_structure['matrix_y']            =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 68, 4));
                $atom_structure['matrix_w']            =  getid3_quicktime::FixedPoint2_30(substr($atom_data, 72, 4));
                $atom_structure['width']               = getid3_quicktime::FixedPoint16_16(substr($atom_data, 76, 4));
                $atom_structure['height']              = getid3_quicktime::FixedPoint16_16(substr($atom_data, 80, 4));

                $atom_structure['flags']['enabled']    = (bool)($atom_structure['flags_raw'] & 0x0001);
                $atom_structure['flags']['in_movie']   = (bool)($atom_structure['flags_raw'] & 0x0002);
                $atom_structure['flags']['in_preview'] = (bool)($atom_structure['flags_raw'] & 0x0004);
                $atom_structure['flags']['in_poster']  = (bool)($atom_structure['flags_raw'] & 0x0008);
                $atom_structure['creation_time_unix']  = (int)($atom_structure['creation_time'] - 2082844800); // DateMac2Unix()
                $atom_structure['modify_time_unix']    = (int)($atom_structure['modify_time']   - 2082844800); // DateMac2Unix()

                if (!isset($info['video']['resolution_x']) || !isset($info['video']['resolution_y'])) {
                    $info['video']['resolution_x'] = $atom_structure['width'];
                    $info['video']['resolution_y'] = $atom_structure['height'];
                }

                if ($atom_structure['flags']['enabled'] == 1) {
                    $info['video']['resolution_x'] = max($info['video']['resolution_x'], $atom_structure['width']);
                    $info['video']['resolution_y'] = max($info['video']['resolution_y'], $atom_structure['height']);
                }

                if (!empty($info['video']['resolution_x']) && !empty($info['video']['resolution_y'])) {
                    $info_quicktime['video']['resolution_x'] = $info['video']['resolution_x'];
                    $info_quicktime['video']['resolution_y'] = $info['video']['resolution_y'];
                } else {
                    unset($info['video']['resolution_x']);
                    unset($info['video']['resolution_y']);
                    unset($info_quicktime['video']);
                }
                break;


            case 'meta': // METAdata atom
                // http://www.geocities.com/xhelmboyx/quicktime/formats/qti-layout.txt
                $next_tag_position = strpos($atom_data, '©');
                while ($next_tag_position < strlen($atom_data)) {
                    $meta_item_size = getid3_lib::BigEndian2Int(substr($atom_data, $next_tag_position - 4, 4)) - 4;
                    if ($meta_item_size == -4) {
                        break;
                    }
                    $meta_item_raw  = substr($atom_data, $next_tag_position, $meta_item_size);
                    $meta_item_key  = substr($meta_item_raw, 0, 4);
                    $meta_item_data = substr($meta_item_raw, 20);
                    $next_tag_position += $meta_item_size + 4;

                    $this->CopyToAppropriateCommentsSection($meta_item_key, $meta_item_data);
                }
                break;

            case 'ftyp': // FileTYPe (?) atom (for MP4 it seems)
                getid3_lib::ReadSequence('BigEndian2Int', $atom_structure, $atom_data, 0,
                    array (
                        'signature' => -4,
                        'unknown_1' => 4,
                        'fourcc'    => -4,
                    )
                );
                break;

            case 'mdat': // Media DATa atom
            case 'free': // FREE space atom
            case 'skip': // SKIP atom
            case 'wide': // 64-bit expansion placeholder atom
                // 'mdat' data is too big to deal with, contains no useful metadata
                // 'free', 'skip' and 'wide' are just padding, contains no useful data at all

                // When writing QuickTime files, it is sometimes necessary to update an atom's size.
                // It is impossible to update a 32-bit atom to a 64-bit atom since the 32-bit atom
                // is only 8 bytes in size, and the 64-bit atom requires 16 bytes. Therefore, QuickTime
                // puts an 8-byte placeholder atom before any atoms it may have to update the size of.
                // In this way, if the atom needs to be converted from a 32-bit to a 64-bit atom, the
                // placeholder atom can be overwritten to obtain the necessary 8 extra bytes.
                // The placeholder atom has a type of kWideAtomPlaceholderType ( 'wide' ).
                break;


            case 'nsav': // NoSAVe atom
                // http://developer.apple.com/technotes/tn/tn2038.html
                $atom_structure['data'] = getid3_lib::BigEndian2Int(substr($atom_data,  0, 4));
                break;

            case 'ctyp': // Controller TYPe atom (seen on QTVR)
                // http://homepages.slingshot.co.nz/~helmboy/quicktime/formats/qtm-layout.txt
                // some controller names are:
                //   0x00 + 'std' for linear movie
                //   'none' for no controls
                $atom_structure['ctyp'] = substr($atom_data, 0, 4);
                switch ($atom_structure['ctyp']) {
                    case 'qtvr':
                        $info['video']['dataformat'] = 'quicktimevr';
                        break;
                }
                break;

            case 'pano': // PANOrama track (seen on QTVR)
                $atom_structure['pano'] = getid3_lib::BigEndian2Int(substr($atom_data,  0, 4));
                break;

             case 'hint': // HINT track
             case 'hinf': //
             case 'hinv': //
             case 'hnti': //
                     $info['quicktime']['hinting'] = true;
                     break;

            case 'imgt': // IMaGe Track reference (kQTVRImageTrackRefType) (seen on QTVR)
                for ($i = 0; $i < ($atom_structure['size'] - 8); $i += 4) {
                    $atom_structure['imgt'][] = getid3_lib::BigEndian2Int(substr($atom_data, $i, 4));
                }
                break;


			// Observed-but-not-handled atom types are just listed here to prevent warnings being generated
			case 'FXTC': // Something to do with Adobe After Effects (?)
			case 'PrmA':
			case 'code':
			case 'FIEL': // this is NOT "fiel" (Field Ordering) as describe here: http://developer.apple.com/documentation/QuickTime/QTFF/QTFFChap3/chapter_4_section_2.html
			case 'tapt': // TrackApertureModeDimensionsAID - http://developer.apple.com/documentation/QuickTime/Reference/QT7-1_Update_Reference/Constants/Constants.html
						// tapt seems to be used to compute the video size [http://www.getid3.org/phpBB3/viewtopic.php?f=4&t=838]
						// * http://lists.apple.com/archives/quicktime-api/2006/Aug/msg00014.html
						// * http://handbrake.fr/irclogs/handbrake-dev/handbrake-dev20080128_pg2.html
			case 'ctts'://  STCompositionOffsetAID             - http://developer.apple.com/documentation/QuickTime/Reference/QTRef_Constants/Reference/reference.html
			case 'cslg'://  STCompositionShiftLeastGreatestAID - http://developer.apple.com/documentation/QuickTime/Reference/QTRef_Constants/Reference/reference.html
			case 'sdtp'://  STSampleDependencyAID              - http://developer.apple.com/documentation/QuickTime/Reference/QTRef_Constants/Reference/reference.html
			case 'stps'://  STPartialSyncSampleAID             - http://developer.apple.com/documentation/QuickTime/Reference/QTRef_Constants/Reference/reference.html
                $atom_structure['data'] = $atom_data;
                break;

            default:
                $getid3->warning('Unknown QuickTime atom type: "'.$atom_name.'" at offset '.$base_offset);
                $atom_structure['data'] = $atom_data;
                break;
        }
        array_pop($atom_hierarchy);
        return $atom_structure;
    }



    private function QuicktimeParseContainerAtom($atom_data, $base_offset, &$atom_hierarchy) {

        if ((strlen($atom_data) == 4) && (getid3_lib::BigEndian2Int($atom_data) == 0x00000000)) {
            return false;
        }

        $atom_structure = false;
        $subatom_offset = 0;

        while ($subatom_offset < strlen($atom_data)) {

            $subatom_size = getid3_lib::BigEndian2Int(substr($atom_data, $subatom_offset + 0, 4));
            $subatom_name =                           substr($atom_data, $subatom_offset + 4, 4);
            $subatom_data =                           substr($atom_data, $subatom_offset + 8, $subatom_size - 8);

            if ($subatom_size == 0) {
                // Furthermore, for historical reasons the list of atoms is optionally
                // terminated by a 32-bit integer set to 0. If you are writing a program
                // to read user data atoms, you should allow for the terminating 0.
                return $atom_structure;
            }

            $atom_structure[] = $this->QuicktimeParseAtom($subatom_name, $subatom_size, $subatom_data, $base_offset + $subatom_offset, $atom_hierarchy);

            $subatom_offset += $subatom_size;
        }
        return $atom_structure;
    }



    private function CopyToAppropriateCommentsSection($key_name, $data) {
        // http://www.geocities.com/xhelmboyx/quicktime/formats/qtm-layout.txt
        // http://atomicparsley.sourceforge.net/mpeg-4files.html
        static $translator = array (
			'aART' => 'album_artist',
			'catg' => 'category',
			'covr' => 'artwork',
			'cpil' => 'compilation',
			'cprt' => 'copyright',
			'desc' => 'description',
			'disk' => 'disc_number',
			'egid' => 'episode_guid',
			'gnre' => 'genre',
			'keyw' => 'keyword',
			'pcst' => 'podcast',
			'pgap' => 'gapless_playback',
			'purd' => 'purchase_date',
			'purl' => 'podcast_url',
			'rtng' => 'rating',
			'stik' => 'stik',
			'tmpo' => 'bpm',
			'trkn' => 'track_number',
			'tves' => 'tv_episode',
			'tvnn' => 'tv_network_name',
			'tvsh' => 'tv_show_name',
			'tvsn' => 'tv_season',
			'©art' => 'artist',
			'©grp' => 'grouping',
			'©lyr' => 'lyrics',
			'©too' => 'encoder',
            '©alb' => 'album',
            '©ART' => 'artist',
            '©aut' => 'author',
            '©cmt' => 'comment',
            '©com' => 'comment',
            '©cpy' => 'copyright',
            '©day' => 'creation_date',
            '©dir' => 'director',
            '©ed1' => 'edit1',
            '©ed2' => 'edit2',
            '©ed3' => 'edit3',
            '©ed4' => 'edit4',
            '©ed5' => 'edit5',
            '©ed6' => 'edit6',
            '©ed7' => 'edit7',
            '©ed8' => 'edit8',
            '©ed9' => 'edit9',
            '©enc' => 'encoder',
            '©fmt' => 'format',
            '©gen' => 'genre',
            '©hst' => 'host_computer',
            '©inf' => 'information',
            '©mak' => 'make',
            '©mod' => 'model',
            '©nam' => 'title',
            '©ope' => 'composer',
            '©prd' => 'producer',
            '©PRD' => 'product',
            '©prf' => 'performers',
            '©req' => 'system_requirements',
            '©src' => 'source_credit',
            '©swr' => 'software',
            '©trk' => 'track',
            '©url' => 'url',
            '©wrn' => 'warning',
            '©wrt' => 'composer',
        );
        if (isset($translator[$key_name])) {
            $this->getid3->info['quicktime']['comments'][$translator[$key_name]][] = $data;
        }
        return true;
    }


    public static function QuicktimeLanguageLookup($language_id) {
        static $lookup = array (
            0   => 'English',
            1   => 'French',
            2   => 'German',
            3   => 'Italian',
            4   => 'Dutch',
            5   => 'Swedish',
            6   => 'Spanish',
            7   => 'Danish',
            8   => 'Portuguese',
            9   => 'Norwegian',
            10  => 'Hebrew',
            11  => 'Japanese',
            12  => 'Arabic',
            13  => 'Finnish',
            14  => 'Greek',
            15  => 'Icelandic',
            16  => 'Maltese',
            17  => 'Turkish',
            18  => 'Croatian',
            19  => 'Chinese (Traditional)',
            20  => 'Urdu',
            21  => 'Hindi',
            22  => 'Thai',
            23  => 'Korean',
            24  => 'Lithuanian',
            25  => 'Polish',
            26  => 'Hungarian',
            27  => 'Estonian',
            28  => 'Lettish',
            28  => 'Latvian',
            29  => 'Saamisk',
            29  => 'Lappish',
            30  => 'Faeroese',
            31  => 'Farsi',
            31  => 'Persian',
            32  => 'Russian',
            33  => 'Chinese (Simplified)',
            34  => 'Flemish',
            35  => 'Irish',
            36  => 'Albanian',
            37  => 'Romanian',
            38  => 'Czech',
            39  => 'Slovak',
            40  => 'Slovenian',
            41  => 'Yiddish',
            42  => 'Serbian',
            43  => 'Macedonian',
            44  => 'Bulgarian',
            45  => 'Ukrainian',
            46  => 'Byelorussian',
            47  => 'Uzbek',
            48  => 'Kazakh',
            49  => 'Azerbaijani',
            50  => 'AzerbaijanAr',
            51  => 'Armenian',
            52  => 'Georgian',
            53  => 'Moldavian',
            54  => 'Kirghiz',
            55  => 'Tajiki',
            56  => 'Turkmen',
            57  => 'Mongolian',
            58  => 'MongolianCyr',
            59  => 'Pashto',
            60  => 'Kurdish',
            61  => 'Kashmiri',
            62  => 'Sindhi',
            63  => 'Tibetan',
            64  => 'Nepali',
            65  => 'Sanskrit',
            66  => 'Marathi',
            67  => 'Bengali',
            68  => 'Assamese',
            69  => 'Gujarati',
            70  => 'Punjabi',
            71  => 'Oriya',
            72  => 'Malayalam',
            73  => 'Kannada',
            74  => 'Tamil',
            75  => 'Telugu',
            76  => 'Sinhalese',
            77  => 'Burmese',
            78  => 'Khmer',
            79  => 'Lao',
            80  => 'Vietnamese',
            81  => 'Indonesian',
            82  => 'Tagalog',
            83  => 'MalayRoman',
            84  => 'MalayArabic',
            85  => 'Amharic',
            86  => 'Tigrinya',
            87  => 'Galla',
            87  => 'Oromo',
            88  => 'Somali',
            89  => 'Swahili',
            90  => 'Ruanda',
            91  => 'Rundi',
            92  => 'Chewa',
            93  => 'Malagasy',
            94  => 'Esperanto',
            128 => 'Welsh',
            129 => 'Basque',
            130 => 'Catalan',
            131 => 'Latin',
            132 => 'Quechua',
            133 => 'Guarani',
            134 => 'Aymara',
            135 => 'Tatar',
            136 => 'Uighur',
            137 => 'Dzongkha',
            138 => 'JavaneseRom'
        );
        return (isset($lookup[$language_id]) ? $lookup[$language_id] : 'invalid');
    }



    public static function QuicktimeVideoCodecLookup($codec_id) {
        static $lookup = array (
            '3IVX' => '3ivx MPEG-4',
            '3IV1' => '3ivx MPEG-4 v1',
            '3IV2' => '3ivx MPEG-4 v2',
            'avr ' => 'AVR-JPEG',
            'base' => 'Base',
            'WRLE' => 'BMP',
            'cvid' => 'Cinepak',
            'clou' => 'Cloud',
            'cmyk' => 'CMYK',
            'yuv2' => 'ComponentVideo',
            'yuvu' => 'ComponentVideoSigned',
            'yuvs' => 'ComponentVideoUnsigned',
            'dvc ' => 'DVC-NTSC',
            'dvcp' => 'DVC-PAL',
            'dvpn' => 'DVCPro-NTSC',
            'dvpp' => 'DVCPro-PAL',
            'fire' => 'Fire',
            'flic' => 'FLC',
            'b48r' => '48RGB',
            'gif ' => 'GIF',
            'smc ' => 'Graphics',
            'h261' => 'H261',
            'h263' => 'H263',
            'IV41' => 'Indeo4',
            'jpeg' => 'JPEG',
            'PNTG' => 'MacPaint',
            'msvc' => 'Microsoft Video1',
            'mjpa' => 'Motion JPEG-A',
            'mjpb' => 'Motion JPEG-B',
            'myuv' => 'MPEG YUV420',
            'dmb1' => 'OpenDML JPEG',
            'kpcd' => 'PhotoCD',
            '8BPS' => 'Planar RGB',
            'png ' => 'PNG',
            'qdrw' => 'QuickDraw',
            'qdgx' => 'QuickDrawGX',
            'raw ' => 'RAW',
            '.SGI' => 'SGI',
            'b16g' => '16Gray',
            'b64a' => '64ARGB',
            'SVQ1' => 'Sorenson Video 1',
            'SVQ1' => 'Sorenson Video 3',
            'syv9' => 'Sorenson YUV9',
            'tga ' => 'Targa',
            'b32a' => '32AlphaGray',
            'tiff' => 'TIFF',
            'path' => 'Vector',
            'rpza' => 'Video',
            'ripl' => 'WaterRipple',
            'WRAW' => 'Windows RAW',
            'y420' => 'YUV420'
        );
        return (isset($lookup[$codec_id]) ? $lookup[$codec_id] : '');
    }



    public static function QuicktimeAudioCodecLookup($codec_id) {

        static $lookup = array (
            '.mp3'          => 'Fraunhofer MPEG Layer-III alias',
            'aac '          => 'ISO/IEC 14496-3 AAC',
            'agsm'          => 'Apple GSM 10:1',
            'alac'          => 'Apple Lossless Audio Codec',
            'alaw'          => 'A-law 2:1',
            'conv'          => 'Sample Format',
            'dvca'          => 'DV',
            'dvi '          => 'DV 4:1',
            'eqal'          => 'Frequency Equalizer',
            'fl32'          => '32-bit Floating Point',
            'fl64'          => '64-bit Floating Point',
            'ima4'          => 'Interactive Multimedia Association 4:1',
            'in24'          => '24-bit Integer',
            'in32'          => '32-bit Integer',
            'lpc '          => 'LPC 23:1',
            'MAC3'          => 'Macintosh Audio Compression/Expansion (MACE) 3:1',
            'MAC6'          => 'Macintosh Audio Compression/Expansion (MACE) 6:1',
            'mixb'          => '8-bit Mixer',
            'mixw'          => '16-bit Mixer',
            'mp4a'          => 'ISO/IEC 14496-3 AAC',
            "MS'\x00\x02"   => 'Microsoft ADPCM',
            "MS'\x00\x11"   => 'DV IMA',
            "MS\x00\x55"    => 'Fraunhofer MPEG Layer III',
            'NONE'          => 'No Encoding',
            'Qclp'          => 'Qualcomm PureVoice',
            'QDM2'          => 'QDesign Music 2',
            'QDMC'          => 'QDesign Music 1',
            'ratb'          => '8-bit Rate',
            'ratw'          => '16-bit Rate',
            'raw '          => 'raw PCM',
            'sour'          => 'Sound Source',
            'sowt'          => 'signed/two\'s complement (Little Endian)',
            'str1'          => 'Iomega MPEG layer II',
            'str2'          => 'Iomega MPEG *layer II',
            'str3'          => 'Iomega MPEG **layer II',
            'str4'          => 'Iomega MPEG ***layer II',
            'twos'          => 'signed/two\'s complement (Big Endian)',
            'ulaw'          => 'mu-law 2:1',
        );

        return (isset($lookup[$codec_id]) ? $lookup[$codec_id] : '');
    }



    public static function QuicktimeDCOMLookup($compression_id) {

        static $lookup = array (
            'zlib' => 'ZLib Deflate',
            'adec' => 'Apple Compression'
        );

        return (isset($lookup[$compression_id]) ? $lookup[$compression_id] : '');
    }



    public static function QuicktimeColorNameLookup($color_depth_id) {

        static $lookup = array (
            1  => '2-color (monochrome)',
            2  => '4-color',
            4  => '16-color',
            8  => '256-color',
            16 => 'thousands (16-bit color)',
            24 => 'millions (24-bit color)',
            32 => 'millions+ (32-bit color)',
            33 => 'black & white',
            34 => '4-gray',
            36 => '16-gray',
            40 => '256-gray',
        );

        return (isset($lookup[$color_depth_id]) ? $lookup[$color_depth_id] : 'invalid');
    }



    public static function NoNullString($null_terminated_string) {

        // remove the single null terminator on null terminated strings
        if (substr($null_terminated_string, strlen($null_terminated_string) - 1, 1) === "\x00") {
            return substr($null_terminated_string, 0, strlen($null_terminated_string) - 1);
        }

        return $null_terminated_string;
    }



    public static function FixedPoint8_8($raw_data) {

        return getid3_lib::BigEndian2Int($raw_data{0}) + (float)(getid3_lib::BigEndian2Int($raw_data{1}) / 256);
    }



    public static function FixedPoint16_16($raw_data) {

        return getid3_lib::BigEndian2Int(substr($raw_data, 0, 2)) + (float)(getid3_lib::BigEndian2Int(substr($raw_data, 2, 2)) / 65536);
    }



    public static function FixedPoint2_30($raw_data) {

        $binary_string = getid3_lib::BigEndian2Bin($raw_data);
        return bindec(substr($binary_string, 0, 2)) + (float)(bindec(substr($binary_string, 2, 30)) / 1073741824);
    }

}

class getid3_write_id3v1 extends getid3_handler_write
{
    public $title;
    public $artist;
    public $album;
    public $year;
    public $genre_id;
    public $genre;
    public $comment;
    public $track;


    public function read() {

        $engine = new getid3;
        $engine->filename = $this->filename;
        $engine->fp = fopen($this->filename, 'rb');
        $engine->include_module('tag.id3v1');

        $tag = new getid3_id3v1($engine);
        $tag->Analyze();

        if (!isset($engine->info['id3v1'])) {
            return;
        }

        $this->title    = $engine->info['id3v1']['title'];
        $this->artist   = $engine->info['id3v1']['artist'];
        $this->album    = $engine->info['id3v1']['album'];
        $this->year     = $engine->info['id3v1']['year'];
        $this->genre_id = $engine->info['id3v1']['genre_id'];
        $this->genre    = $engine->info['id3v1']['genre'];
        $this->comment  = $engine->info['id3v1']['comment'];
        $this->track    = $engine->info['id3v1']['track'];

        return true;
    }


    public function write() {

        if (!$fp = @fopen($this->filename, 'r+b')) {
            throw new getid3_exception('Could not open r+b: ' . $this->filename);
        }

        
        fseek($fp, -128, SEEK_END);

        
        if (fread($fp, 3) == 'TAG') {
            fseek($fp, -128, SEEK_END);
        }

        
        else {
            fseek($fp, 0, SEEK_END);
        }

        fwrite($fp, $this->generate_tag(), 128);

        fclose($fp);
        clearstatcache();

        return true;
    }


    protected function generate_tag() {

        $result  = 'TAG';
        $result .= str_pad(trim(substr($this->title,  0, 30)), 30, "\x00", STR_PAD_RIGHT);
        $result .= str_pad(trim(substr($this->artist, 0, 30)), 30, "\x00", STR_PAD_RIGHT);
        $result .= str_pad(trim(substr($this->album,  0, 30)), 30, "\x00", STR_PAD_RIGHT);
        $result .= str_pad(trim(substr($this->year,   0,  4)),  4, "\x00", STR_PAD_LEFT);

        if (!empty($this->track) && ($this->track > 0) && ($this->track <= 255)) {

            $result .= str_pad(trim(substr($this->comment, 0, 28)), 28, "\x00", STR_PAD_RIGHT);
            $result .= "\x00";
            $result .= chr($this->track);
        }
        else {
            $result .= str_pad(trim(substr($comment, 0, 30)), 30, "\x00", STR_PAD_RIGHT);
        }

        
        if ($this->genre && $this->genre_id) {
            if ($this->genre != getid3_id3v1::LookupGenreName($this->genre_id)) {
                throw new getid3_exception('Genre and genre_id does not match. Unset one and the other will be determined automatically.');
            }
        }

        
        elseif ($this->genre) {
            $this->genre_id = getid3_id3v1::LookupGenreID($this->genre);
        }

        
        else {
            if ($this->genre_id < 0  ||  $this->genre_id > 147) {
                $this->genre_id = 255; 
            }
            $this->genre = getid3_id3v1::LookupGenreName($this->genre_id);
        }

        $result .= chr(intval($this->genre_id));

        return $result;
    }


    public function remove() {

        if (!$fp = @fopen($this->filename, 'r+b')) {
            throw new getid3_exception('Could not open r+b: ' . $filename);
        }

        fseek($fp, -128, SEEK_END);
        if (fread($fp, 3) == 'TAG') {
            ftruncate($fp, filesize($this->filename) - 128);
            fclose($fp);
            clearstatcache();
        }

        
        return true;
    }

}

class getid3_id3v2 extends getid3_handler
{

    public $option_starting_offset = 0;


    public function Analyze() {

        $getid3 = $this->getid3;

        
        $getid3->include_module('tag.id3v1');

        if ($getid3->option_tags_images) {
            $getid3->include_module('lib.image_size');
        }


        
        $getid3->info['id3v2']['header'] = true;
        $info_id3v2          = &$getid3->info['id3v2'];
        $info_id3v2['flags'] = array ();
        $info_id3v2_flags    = &$info_id3v2['flags'];


        $this->fseek($this->option_starting_offset, SEEK_SET);
        $header = $this->fread(10);
        if (substr($header, 0, 3) == 'ID3'  &&  strlen($header) == 10) {

            $info_id3v2['majorversion'] = ord($header{3});
            $info_id3v2['minorversion'] = ord($header{4});

            
            $id3v2_major_version = &$info_id3v2['majorversion'];

        } else {
            unset($getid3->info['id3v2']);
            return false;

        }

        if ($id3v2_major_version > 4) { 
            throw new getid3_exception('this script only parses up to ID3v2.4.x - this tag is ID3v2.'.$id3v2_major_version.'.'.$info_id3v2['minorversion']);
        }

        $id3_flags = ord($header{5});
        switch ($id3v2_major_version) {
            case 2:
                
                $info_id3v2_flags['unsynch']     = (bool)($id3_flags & 0x80); 
                $info_id3v2_flags['compression'] = (bool)($id3_flags & 0x40); 
                break;

            case 3:
                
                $info_id3v2_flags['unsynch']     = (bool)($id3_flags & 0x80); 
                $info_id3v2_flags['exthead']     = (bool)($id3_flags & 0x40); 
                $info_id3v2_flags['experim']     = (bool)($id3_flags & 0x20); 
                break;

            case 4:
                
                $info_id3v2_flags['unsynch']     = (bool)($id3_flags & 0x80); 
                $info_id3v2_flags['exthead']     = (bool)($id3_flags & 0x40); 
                $info_id3v2_flags['experim']     = (bool)($id3_flags & 0x20); 
                $info_id3v2_flags['isfooter']    = (bool)($id3_flags & 0x10); 
                break;
        }

        $info_id3v2['headerlength'] = getid3_lib::BigEndianSyncSafe2Int(substr($header, 6, 4)) + 10; 

        $info_id3v2['tag_offset_start'] = $this->option_starting_offset;
        $info_id3v2['tag_offset_end']   = $info_id3v2['tag_offset_start'] + $info_id3v2['headerlength'];


        

        
        
        
        
        
        
        

        $size_of_frames = $info_id3v2['headerlength'] - 10; 
        if (@$info_id3v2['exthead']['length']) {
            $size_of_frames -= ($info_id3v2['exthead']['length'] + 4);
        }

        if (@$info_id3v2_flags['isfooter']) {
            $size_of_frames -= 10; 
        }

        if ($size_of_frames > 0) {
            $frame_data = $this->fread($size_of_frames); 

            
            if (@$info_id3v2_flags['unsynch'] && ($id3v2_major_version <= 3)) {
                $frame_data = str_replace("\xFF\x00", "\xFF", $frame_data);
            }

            
            
            
            
            

             
             $frame_data_offset = 10; 

             
             if (@$info_id3v2_flags['exthead']) {
                     $extended_header_offset = 0;

                 if ($id3v2_major_version == 3) {

                     
                     
                     
                     
                     
                     

                     $info_id3v2['exthead']['length'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 4), 0);
                     $extended_header_offset += 4;

                     $info_id3v2['exthead']['flag_bytes'] = 2;
                     $info_id3v2['exthead']['flag_raw'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, $info_id3v2['exthead']['flag_bytes']));
                     $extended_header_offset += $info_id3v2['exthead']['flag_bytes'];

                     $info_id3v2['exthead']['flags']['crc'] = (bool) ($info_id3v2['exthead']['flag_raw'] & 0x8000);

                     $info_id3v2['exthead']['padding_size'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 4));
                     $extended_header_offset += 4;

                     if ($info_id3v2['exthead']['flags']['crc']) {
                         $info_id3v2['exthead']['flag_data']['crc'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 4));
                         $extended_header_offset += 4;
                     }
                     $extended_header_offset += $info_id3v2['exthead']['padding_size'];

                 }

                 elseif ($id3v2_major_version == 4) {

                     
                     
                     
                     
                     
                     
                     
                     
                     
                     
                     
                     

                     $info_id3v2['exthead']['length']     = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 4), 1);
                     $extended_header_offset += 4;

                     $info_id3v2['exthead']['flag_bytes'] = 1;
                     $info_id3v2['exthead']['flag_raw'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, $info_id3v2['exthead']['flag_bytes']));
                     $extended_header_offset += $info_id3v2['exthead']['flag_bytes'];

                     $info_id3v2['exthead']['flags']['update']       = (bool) ($info_id3v2['exthead']['flag_raw'] & 0x4000);
                     $info_id3v2['exthead']['flags']['crc']          = (bool) ($info_id3v2['exthead']['flag_raw'] & 0x2000);
                     $info_id3v2['exthead']['flags']['restrictions'] = (bool) ($info_id3v2['exthead']['flag_raw'] & 0x1000);

                     if ($info_id3v2['exthead']['flags']['crc']) {
                         $info_id3v2['exthead']['flag_data']['crc'] = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 5), 1);
                         $extended_header_offset += 5;
                     }
                     if ($info_id3v2['exthead']['flags']['restrictions']) {
                         
                         $restrictions_raw = getid3_lib::BigEndian2Int(substr($frame_data, $extended_header_offset, 1));
                         $extended_header_offset += 1;
                         $info_id3v2['exthead']['flags']['restrictions']['tagsize']  = ($restrictions_raw && 0xC0) >> 6; 
                         $info_id3v2['exthead']['flags']['restrictions']['textenc']  = ($restrictions_raw && 0x20) >> 5; 
                         $info_id3v2['exthead']['flags']['restrictions']['textsize'] = ($restrictions_raw && 0x18) >> 3; 
                         $info_id3v2['exthead']['flags']['restrictions']['imgenc']   = ($restrictions_raw && 0x04) >> 2; 
                             $info_id3v2['exthead']['flags']['restrictions']['imgsize']  = ($restrictions_raw && 0x03) >> 0; 
                     }

                 }
                 $frame_data_offset += $extended_header_offset;
                 $frame_data = substr($frame_data, $extended_header_offset);
             } 






            while (isset($frame_data) && (strlen($frame_data) > 0)) { 
                if (strlen($frame_data) <= ($id3v2_major_version == 2 ? 6 : 10)) {
                    
                    $info_id3v2['padding']['start']  = $frame_data_offset;
                    $info_id3v2['padding']['length'] = strlen($frame_data);
                    $info_id3v2['padding']['valid']  = true;
                    for ($i = 0; $i < $info_id3v2['padding']['length']; $i++) {
                        if ($frame_data{$i} != "\x00") {
                            $info_id3v2['padding']['valid'] = false;
                            $info_id3v2['padding']['errorpos'] = $info_id3v2['padding']['start'] + $i;
                            $getid3->warning('Invalid ID3v2 padding found at offset '.$info_id3v2['padding']['errorpos'].' (the remaining '.($info_id3v2['padding']['length'] - $i).' bytes are considered invalid)');
                            break;
                        }
                    }
                    break; 
                }

                if ($id3v2_major_version == 2) {
                    
                    
                    

                    $frame_header = substr($frame_data, 0, 6); 
                    $frame_data    = substr($frame_data, 6);    
                    $frame_name   = substr($frame_header, 0, 3);
                    $frame_size   = getid3_lib::BigEndian2Int(substr($frame_header, 3, 3));
                    $frame_flags  = 0; 


                } elseif ($id3v2_major_version > 2) {

                    
                    
                    

                    $frame_header = substr($frame_data, 0, 10); 
                    $frame_data    = substr($frame_data, 10);    

                    $frame_name = substr($frame_header, 0, 4);

                    if ($id3v2_major_version == 3) {
                        $frame_size = getid3_lib::BigEndian2Int(substr($frame_header, 4, 4)); 

                    } else { 
                        $frame_size = getid3_lib::BigEndianSyncSafe2Int(substr($frame_header, 4, 4)); 
                    }

                    if ($frame_size < (strlen($frame_data) + 4)) {
                        $nextFrameID = substr($frame_data, $frame_size, 4);
                        if (getid3_id3v2::IsValidID3v2FrameName($nextFrameID, $id3v2_major_version)) {
                            
                        } elseif (($frame_name == "\x00".'MP3') || ($frame_name == "\x00\x00".'MP') || ($frame_name == ' MP3') || ($frame_name == 'MP3e')) {
                            
                        } elseif (($id3v2_major_version == 4) && (getid3_id3v2::IsValidID3v2FrameName(substr($frame_data, getid3_lib::BigEndian2Int(substr($frame_header, 4, 4)), 4), 3))) {
                            $getid3->warning('ID3v2 tag written as ID3v2.4, but with non-synchsafe integers (ID3v2.3 style). Older versions of (Helium2; iTunes) are known culprits of this. Tag has been parsed as ID3v2.3');
                            $id3v2_major_version = 3;
                            $frame_size = getid3_lib::BigEndian2Int(substr($frame_header, 4, 4)); 
                        }
                    }


                    $frame_flags = getid3_lib::BigEndian2Int(substr($frame_header, 8, 2));
                }

                if ((($id3v2_major_version == 2) && ($frame_name == "\x00\x00\x00")) || ($frame_name == "\x00\x00\x00\x00")) {
                    

                    $info_id3v2['padding']['start']  = $frame_data_offset;
                    $info_id3v2['padding']['length'] = strlen($frame_header) + strlen($frame_data);
                    $info_id3v2['padding']['valid']  = true;

                    $len = strlen($frame_data);
                    for ($i = 0; $i < $len; $i++) {
                        if ($frame_data{$i} != "\x00") {
                            $info_id3v2['padding']['valid'] = false;
                            $info_id3v2['padding']['errorpos'] = $info_id3v2['padding']['start'] + $i;
                            $getid3->warning('Invalid ID3v2 padding found at offset '.$info_id3v2['padding']['errorpos'].' (the remaining '.($info_id3v2['padding']['length'] - $i).' bytes are considered invalid)');
                            break;
                        }
                    }
                    break; 
                }

                if ($frame_name == 'COM ') {
                    $getid3->warning('error parsing "'.$frame_name.'" ('.$frame_data_offset.' bytes into the ID3v2.'.$id3v2_major_version.' tag). (ERROR: IsValidID3v2FrameName("'.str_replace("\x00", ' ', $frame_name).'", '.$id3v2_major_version.'))). [Note: this particular error has been known to happen with tags edited by iTunes (versions "X v2.0.3", "v3.0.1" are known-guilty, probably others too)]');
                    $frame_name = 'COMM';
                }
                if (($frame_size <= strlen($frame_data)) && (getid3_id3v2::IsValidID3v2FrameName($frame_name, $id3v2_major_version))) {

                    unset($parsed_frame);
                    $parsed_frame['frame_name']      = $frame_name;
                    $parsed_frame['frame_flags_raw'] = $frame_flags;
                    $parsed_frame['data']            = substr($frame_data, 0, $frame_size);
                    $parsed_frame['datalength']      = (int)($frame_size);
                    $parsed_frame['dataoffset']      = $frame_data_offset;

                    $this->ParseID3v2Frame($parsed_frame);
                    $info_id3v2[$frame_name][] = $parsed_frame;

                    $frame_data = substr($frame_data, $frame_size);

                } else { 

                    if ($frame_size <= strlen($frame_data)) {

                        if (getid3_id3v2::IsValidID3v2FrameName(substr($frame_data, $frame_size, 4), $id3v2_major_version)) {

                            
                            $frame_data = substr($frame_data, $frame_size);
                            $getid3->warning('Next ID3v2 frame is valid, skipping current frame.');

                        } else {

                            
                            throw new getid3_exception('Next ID3v2 frame is also invalid, aborting processing.');

                        }

                    } elseif ($frame_size == strlen($frame_data)) {

                        
                        $getid3->warning('This was the last ID3v2 frame.');

                    } else {

                        
                        $frame_data = null;
                        $getid3->warning('Invalid ID3v2 frame size, aborting.');

                    }
                    if (!getid3_id3v2::IsValidID3v2FrameName($frame_name, $id3v2_major_version)) {

                        switch ($frame_name) {

                            case "\x00\x00".'MP':
                            case "\x00".'MP3':
                            case ' MP3':
                            case 'MP3e':
                            case "\x00".'MP':
                            case ' MP':
                            case 'MP3':
                                $getid3->warning('error parsing "'.$frame_name.'" ('.$frame_data_offset.' bytes into the ID3v2.'.$id3v2_major_version.' tag). (ERROR: !IsValidID3v2FrameName("'.str_replace("\x00", ' ', $frame_name).'", '.$id3v2_major_version.'))). [Note: this particular error has been known to happen with tags edited by "MP3ext (www.mutschler.de/mp3ext/)"]');
                                break;

                            default:
                                $getid3->warning('error parsing "'.$frame_name.'" ('.$frame_data_offset.' bytes into the ID3v2.'.$id3v2_major_version.' tag). (ERROR: !IsValidID3v2FrameName("'.str_replace("\x00", ' ', $frame_name).'", '.$id3v2_major_version.'))).');
                                break;
                        }

                    } elseif ($frame_size > strlen(@$frame_data)){

                        throw new getid3_exception('error parsing "'.$frame_name.'" ('.$frame_data_offset.' bytes into the ID3v2.'.$id3v2_major_version.' tag). (ERROR: $frame_size ('.$frame_size.') > strlen($frame_data) ('.strlen($frame_data).')).');

                    } else {

                        throw new getid3_exception('error parsing "'.$frame_name.'" ('.$frame_data_offset.' bytes into the ID3v2.'.$id3v2_major_version.' tag).');

                    }

                }
                $frame_data_offset += ($frame_size + ($id3v2_major_version == 2 ? 6 : 10));

            }

        }


        

        
        
        
        
        

        if (isset($info_id3v2_flags['isfooter']) && $info_id3v2_flags['isfooter']) {
            $footer = fread ($getid3->fp, 10);
            if (substr($footer, 0, 3) == '3DI') {
                $info_id3v2['footer'] = true;
                $info_id3v2['majorversion_footer'] = ord($footer{3});
                $info_id3v2['minorversion_footer'] = ord($footer{4});
            }
            if ($info_id3v2['majorversion_footer'] <= 4) {
                $id3_flags = ord($footer{5});
                $info_id3v2_flags['unsynch_footer']  = (bool)($id3_flags & 0x80);
                $info_id3v2_flags['extfoot_footer']  = (bool)($id3_flags & 0x40);
                $info_id3v2_flags['experim_footer']  = (bool)($id3_flags & 0x20);
                $info_id3v2_flags['isfooter_footer'] = (bool)($id3_flags & 0x10);

                $info_id3v2['footerlength'] = getid3_lib::BigEndianSyncSafe2Int(substr($footer, 6, 4));
            }
        } 

        if (isset($info_id3v2['comments']['genre'])) {
            foreach ($info_id3v2['comments']['genre'] as $key => $value) {
                unset($info_id3v2['comments']['genre'][$key]);
                $info_id3v2['comments'] = getid3_id3v2::array_merge_noclobber($info_id3v2['comments'], getid3_id3v2::ParseID3v2GenreString($value));
            }
        }

        if (isset($info_id3v2['comments']['track'])) {
            foreach ($info_id3v2['comments']['track'] as $key => $value) {
                if (strstr($value, '/')) {
                    list($info_id3v2['comments']['track'][$key], $info_id3v2['comments']['totaltracks'][$key]) = explode('/', $info_id3v2['comments']['track'][$key]);
                }
            }
        }

        
        if (!isset($info_id3v2['comments']['year']) && preg_match('#^([0-9]{4})#', @$info_id3v2['comments']['recording_time'][0], $matches)) {
			$info_id3v2['comments']['year'] = array ($matches[1]);
		}

        
        $getid3->info['avdataoffset'] = $info_id3v2['headerlength'];
        if (isset($info_id3v2['footer'])) {
            $getid3->info['avdataoffset'] += 10;
        }

        return true;
    }



    private function ParseID3v2Frame(&$parsed_frame) {

        $getid3 = $this->getid3;

        $id3v2_major_version = $getid3->info['id3v2']['majorversion'];

        $frame_name_long  = getid3_id3v2::FrameNameLongLookup($parsed_frame['frame_name']);
        if ($frame_name_long) {
            $parsed_frame['framenamelong']  = $frame_name_long;
        }

        $frame_name_short = getid3_id3v2::FrameNameShortLookup($parsed_frame['frame_name']);
        if ($frame_name_short) {
            $parsed_frame['framenameshort']  = $frame_name_short;
        }

        if ($id3v2_major_version >= 3) { 

            if ($id3v2_major_version == 3) {

                
                

                $parsed_frame['flags']['TagAlterPreservation']  = (bool)($parsed_frame['frame_flags_raw'] & 0x8000); 
                $parsed_frame['flags']['FileAlterPreservation'] = (bool)($parsed_frame['frame_flags_raw'] & 0x4000); 
                $parsed_frame['flags']['ReadOnly']              = (bool)($parsed_frame['frame_flags_raw'] & 0x2000); 
                $parsed_frame['flags']['compression']           = (bool)($parsed_frame['frame_flags_raw'] & 0x0080); 
                $parsed_frame['flags']['Encryption']            = (bool)($parsed_frame['frame_flags_raw'] & 0x0040); 
                $parsed_frame['flags']['GroupingIdentity']      = (bool)($parsed_frame['frame_flags_raw'] & 0x0020); 


            } elseif ($id3v2_major_version == 4) {

                
                

                $parsed_frame['flags']['TagAlterPreservation']  = (bool)($parsed_frame['frame_flags_raw'] & 0x4000); 
                $parsed_frame['flags']['FileAlterPreservation'] = (bool)($parsed_frame['frame_flags_raw'] & 0x2000); 
                $parsed_frame['flags']['ReadOnly']              = (bool)($parsed_frame['frame_flags_raw'] & 0x1000); 
                $parsed_frame['flags']['GroupingIdentity']      = (bool)($parsed_frame['frame_flags_raw'] & 0x0040); 
                $parsed_frame['flags']['compression']           = (bool)($parsed_frame['frame_flags_raw'] & 0x0008); 
                $parsed_frame['flags']['Encryption']            = (bool)($parsed_frame['frame_flags_raw'] & 0x0004); 
                $parsed_frame['flags']['Unsynchronisation']     = (bool)($parsed_frame['frame_flags_raw'] & 0x0002); 
                $parsed_frame['flags']['DataLengthIndicator']   = (bool)($parsed_frame['frame_flags_raw'] & 0x0001); 

                
                if ($parsed_frame['flags']['Unsynchronisation']) {
                    $parsed_frame['data'] = str_replace("\xFF\x00", "\xFF", $parsed_frame['data']);
                }
            }

            
            if ($parsed_frame['flags']['compression']) {
                $parsed_frame['decompressed_size'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], 0, 4));

                if (!function_exists('gzuncompress')) {
                    $getid3->warning('gzuncompress() support required to decompress ID3v2 frame "'.$parsed_frame['frame_name'].'"');
                } elseif ($decompressed_data = @gzuncompress(substr($parsed_frame['data'], 4))) {
                    $parsed_frame['data'] = $decompressed_data;
                } else {
                    $getid3->warning('gzuncompress() failed on compressed contents of ID3v2 frame "'.$parsed_frame['frame_name'].'"');
                }
            }
        }


        if (isset($parsed_frame['datalength']) && ($parsed_frame['datalength'] == 0)) {

            $warning = 'Frame "'.$parsed_frame['frame_name'].'" at offset '.$parsed_frame['dataoffset'].' has no data portion';
            switch ($parsed_frame['frame_name']) {
                case 'WCOM':
                    $warning .= ' (this is known to happen with files tagged by RioPort)';
                    break;

                default:
                    break;
            }
            $getid3->warning($warning);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'UFID')) ||   
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'UFI'))) {    

            
            
            

            
            

            $frame_terminator_pos = strpos($parsed_frame['data'], "\x00");
            $frame_id_string = substr($parsed_frame['data'], 0, $frame_terminator_pos);
            $parsed_frame['ownerid'] = $frame_id_string;
            $parsed_frame['data'] = substr($parsed_frame['data'], $frame_terminator_pos + strlen("\x00"));
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'TXXX')) ||   
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'TXX'))) {    

            
            
            

            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});

            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $parsed_frame['encodingid']  = $frame_text_encoding;
            $parsed_frame['encoding']    = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['description'] = $frame_description;
            $parsed_frame['data'] = substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));
            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = trim($getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']));
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ($parsed_frame['frame_name']{0} == 'T') { 

            
            

            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }

            $parsed_frame['data'] = (string)substr($parsed_frame['data'], $frame_offset);

            $parsed_frame['encodingid'] = $frame_text_encoding;
            $parsed_frame['encoding']   = $this->TextEncodingNameLookup($frame_text_encoding);

            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {

                
                $string = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']);
                if ($string[strlen($string) - 1] == "\x00") {
                    $string = substr($string, 0, strlen($string) - 1);
                }
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $string;
                unset($string);
            }
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'WXXX')) ||   
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'WXX'))) {    

            
            
            

            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);

            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $parsed_frame['data'] = substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));

            $frame_terminator_pos = strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            if ($frame_terminator_pos) {
                
                
                $frame_urldata = (string)substr($parsed_frame['data'], 0, $frame_terminator_pos);
            } else {
                
                $frame_urldata = (string)$parsed_frame['data'];
            }

            $parsed_frame['encodingid']  = $frame_text_encoding;
            $parsed_frame['encoding']    = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['url']         = $frame_urldata;
            $parsed_frame['description'] = $frame_description;
            if (!empty($parsed_frame['framenameshort']) && $parsed_frame['url']) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['url']);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ($parsed_frame['frame_name']{0} == 'W') {        

            
            
            

            

            $parsed_frame['url'] = trim($parsed_frame['data']);
            if (!empty($parsed_frame['framenameshort']) && $parsed_frame['url']) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $parsed_frame['url'];
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version == 3) && ($parsed_frame['frame_name'] == 'IPLS')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'IPL'))) {     

            
            

            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $parsed_frame['encodingid'] = $frame_text_encoding;
            $parsed_frame['encoding']   = $this->TextEncodingNameLookup($parsed_frame['encodingid']);

            $parsed_frame['data']       = (string)substr($parsed_frame['data'], $frame_offset);
            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']);
            }
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'MCDI')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'MCI'))) {     

            
            

            

            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $parsed_frame['data'];
            }
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'ETCO')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'ETC'))) {     

            
            

            
            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['timestampformat'] = ord($parsed_frame['data']{$frame_offset++});

            while ($frame_offset < strlen($parsed_frame['data'])) {
                $parsed_frame['typeid']    = $parsed_frame['data']{$frame_offset++};
                $parsed_frame['type']      = getid3_id3v2::ETCOEventLookup($parsed_frame['typeid']);
                $parsed_frame['timestamp'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
                $frame_offset += 4;
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'MLLT')) ||     
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'MLL'))) {      

            
            

            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['framesbetweenreferences'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], 0, 2));
            $parsed_frame['bytesbetweenreferences']  = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], 2, 3));
            $parsed_frame['msbetweenreferences']     = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], 5, 3));
            $parsed_frame['bitsforbytesdeviation']   = getid3_lib::BigEndian2Int($parsed_frame['data'][8]);
            $parsed_frame['bitsformsdeviation']      = getid3_lib::BigEndian2Int($parsed_frame['data'][9]);
            $parsed_frame['data'] = substr($parsed_frame['data'], 10);

            while ($frame_offset < strlen($parsed_frame['data'])) {
                $deviation_bitstream .= getid3_lib::BigEndian2Bin($parsed_frame['data']{$frame_offset++});
            }
            $reference_counter = 0;
            while (strlen($deviation_bitstream) > 0) {
                $parsed_frame[$reference_counter]['bytedeviation'] = bindec(substr($deviation_bitstream, 0, $parsed_frame['bitsforbytesdeviation']));
                $parsed_frame[$reference_counter]['msdeviation']   = bindec(substr($deviation_bitstream, $parsed_frame['bitsforbytesdeviation'], $parsed_frame['bitsformsdeviation']));
                $deviation_bitstream = substr($deviation_bitstream, $parsed_frame['bitsforbytesdeviation'] + $parsed_frame['bitsformsdeviation']);
                $reference_counter++;
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'SYTC')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'STC'))) {     

            
            

            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['timestampformat'] = ord($parsed_frame['data']{$frame_offset++});
            $timestamp_counter = 0;
            while ($frame_offset < strlen($parsed_frame['data'])) {
                $parsed_frame[$timestamp_counter]['tempo'] = ord($parsed_frame['data']{$frame_offset++});
                if ($parsed_frame[$timestamp_counter]['tempo'] == 255) {
                    $parsed_frame[$timestamp_counter]['tempo'] += ord($parsed_frame['data']{$frame_offset++});
                }
                $parsed_frame[$timestamp_counter]['timestamp'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
                $frame_offset += 4;
                $timestamp_counter++;
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'USLT')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'ULT'))) {     

            
            
            

            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_language = substr($parsed_frame['data'], $frame_offset, 3);
            $frame_offset += 3;
            if ($frame_offset > strlen($parsed_frame['data'])) {
                $frame_offset = strlen($parsed_frame['data']) - 1;
            }
            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $parsed_frame['data'] = substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));

            $parsed_frame['encodingid']   = $frame_text_encoding;
            $parsed_frame['encoding']     = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['data']         = $parsed_frame['data'];
            $parsed_frame['language']     = $frame_language;
            $parsed_frame['languagename'] = getid3_id3v2::LanguageLookup($frame_language, false);
            $parsed_frame['description']  = $frame_description;
            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'SYLT')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'SLT'))) {     

            
            
            

            
            
            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_language = substr($parsed_frame['data'], $frame_offset, 3);
            $frame_offset += 3;
            $parsed_frame['timestampformat'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['contenttypeid']   = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['contenttype']     = getid3_id3v2::SYTLContentTypeLookup($parsed_frame['contenttypeid']);
            $parsed_frame['encodingid']      = $frame_text_encoding;
            $parsed_frame['encoding']        = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['language']        = $frame_language;
            $parsed_frame['languagename']    = getid3_id3v2::LanguageLookup($frame_language, false);

            $timestamp_index = 0;
            $frame_remaining_data = substr($parsed_frame['data'], $frame_offset);
            while (strlen($frame_remaining_data)) {
                $frame_offset = 0;
                $frame_terminator_pos = strpos($frame_remaining_data, getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));
                if ($frame_terminator_pos === false) {
                    $frame_remaining_data = '';
                } else {
                    if (ord(substr($frame_remaining_data, $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                        $frame_terminator_pos++; 
                    }
                    $parsed_frame['lyrics'][$timestamp_index]['data'] = substr($frame_remaining_data, $frame_offset, $frame_terminator_pos - $frame_offset);

                    $frame_remaining_data = substr($frame_remaining_data, $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));
                    if (($timestamp_index == 0) && (ord($frame_remaining_data{0}) != 0)) {
                        
                    } else {
                        $parsed_frame['lyrics'][$timestamp_index]['timestamp'] = getid3_lib::BigEndian2Int(substr($frame_remaining_data, 0, 4));
                        $frame_remaining_data = substr($frame_remaining_data, 4);
                    }
                    $timestamp_index++;
                }
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'COMM')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'COM'))) {     

            
            
            

            
            
            
            

            if (strlen($parsed_frame['data']) < 5) {

                $getid3->warning('Invalid data (too short) for "'.$parsed_frame['frame_name'].'" frame at offset '.$parsed_frame['dataoffset']);
                return true;
            }

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_language = substr($parsed_frame['data'], $frame_offset, 3);
            $frame_offset += 3;
            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $frame_text = (string)substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));

            $parsed_frame['encodingid']   = $frame_text_encoding;
            $parsed_frame['encoding']     = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['language']     = $frame_language;
            $parsed_frame['languagename'] = getid3_id3v2::LanguageLookup($frame_language, false);
            $parsed_frame['description']  = $frame_description;
            $parsed_frame['data']         = $frame_text;
            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']);
            }
            return true;
        }


        if (($id3v2_major_version >= 4) && ($parsed_frame['frame_name'] == 'RVA2')) {   

            
            
            

            
            
            
            
            
            
            
            

            $frame_terminator_pos = strpos($parsed_frame['data'], "\x00");
            $frame_id_string = substr($parsed_frame['data'], 0, $frame_terminator_pos);
            if (ord($frame_id_string) === 0) {
                $frame_id_string = '';
            }
            $frame_remaining_data = substr($parsed_frame['data'], $frame_terminator_pos + strlen("\x00"));
            $parsed_frame['description'] = $frame_id_string;

            while (strlen($frame_remaining_data)) {
                $frame_offset = 0;
                $frame_channeltypeid = ord(substr($frame_remaining_data, $frame_offset++, 1));
                $parsed_frame[$frame_channeltypeid]['channeltypeid']  = $frame_channeltypeid;
                $parsed_frame[$frame_channeltypeid]['channeltype']    = getid3_id3v2::RVA2ChannelTypeLookup($frame_channeltypeid);
                $parsed_frame[$frame_channeltypeid]['volumeadjust']   = getid3_lib::BigEndian2Int(substr($frame_remaining_data, $frame_offset, 2), true); 
                $frame_offset += 2;
                $parsed_frame[$frame_channeltypeid]['bitspeakvolume'] = ord(substr($frame_remaining_data, $frame_offset++, 1));
                $frame_bytespeakvolume = ceil($parsed_frame[$frame_channeltypeid]['bitspeakvolume'] / 8);
                $parsed_frame[$frame_channeltypeid]['peakvolume']     = getid3_lib::BigEndian2Int(substr($frame_remaining_data, $frame_offset, $frame_bytespeakvolume));
                $frame_remaining_data = substr($frame_remaining_data, $frame_offset + $frame_bytespeakvolume);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version == 3) && ($parsed_frame['frame_name'] == 'RVAD')) ||     
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'RVA'))) {      

            
            

            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $frame_incrdecrflags = getid3_lib::BigEndian2Bin($parsed_frame['data']{$frame_offset++});
            $parsed_frame['incdec']['right'] = (bool)substr($frame_incrdecrflags, 6, 1);
            $parsed_frame['incdec']['left']  = (bool)substr($frame_incrdecrflags, 7, 1);
            $parsed_frame['bitsvolume'] = ord($parsed_frame['data']{$frame_offset++});
            $frame_bytesvolume = ceil($parsed_frame['bitsvolume'] / 8);
            $parsed_frame['volumechange']['right'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
            if ($parsed_frame['incdec']['right'] === false) {
                $parsed_frame['volumechange']['right'] *= -1;
            }
            $frame_offset += $frame_bytesvolume;
            $parsed_frame['volumechange']['left'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
            if ($parsed_frame['incdec']['left'] === false) {
                $parsed_frame['volumechange']['left'] *= -1;
            }
            $frame_offset += $frame_bytesvolume;
            $parsed_frame['peakvolume']['right'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
            $frame_offset += $frame_bytesvolume;
            $parsed_frame['peakvolume']['left']  = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
            $frame_offset += $frame_bytesvolume;
            if ($id3v2_major_version == 3) {
                $parsed_frame['data'] = substr($parsed_frame['data'], $frame_offset);
                if (strlen($parsed_frame['data']) > 0) {
                    $parsed_frame['incdec']['rightrear'] = (bool)substr($frame_incrdecrflags, 4, 1);
                    $parsed_frame['incdec']['leftrear']  = (bool)substr($frame_incrdecrflags, 5, 1);
                    $parsed_frame['volumechange']['rightrear'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    if ($parsed_frame['incdec']['rightrear'] === false) {
                        $parsed_frame['volumechange']['rightrear'] *= -1;
                    }
                    $frame_offset += $frame_bytesvolume;
                    $parsed_frame['volumechange']['leftrear'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    if ($parsed_frame['incdec']['leftrear'] === false) {
                        $parsed_frame['volumechange']['leftrear'] *= -1;
                    }
                    $frame_offset += $frame_bytesvolume;
                    $parsed_frame['peakvolume']['rightrear'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    $frame_offset += $frame_bytesvolume;
                    $parsed_frame['peakvolume']['leftrear']  = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    $frame_offset += $frame_bytesvolume;
                }
                $parsed_frame['data'] = substr($parsed_frame['data'], $frame_offset);
                if (strlen($parsed_frame['data']) > 0) {
                    $parsed_frame['incdec']['center'] = (bool)substr($frame_incrdecrflags, 3, 1);
                    $parsed_frame['volumechange']['center'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    if ($parsed_frame['incdec']['center'] === false) {
                        $parsed_frame['volumechange']['center'] *= -1;
                    }
                    $frame_offset += $frame_bytesvolume;
                    $parsed_frame['peakvolume']['center'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    $frame_offset += $frame_bytesvolume;
                }
                $parsed_frame['data'] = substr($parsed_frame['data'], $frame_offset);
                if (strlen($parsed_frame['data']) > 0) {
                    $parsed_frame['incdec']['bass'] = (bool)substr($frame_incrdecrflags, 2, 1);
                    $parsed_frame['volumechange']['bass'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    if ($parsed_frame['incdec']['bass'] === false) {
                        $parsed_frame['volumechange']['bass'] *= -1;
                    }
                    $frame_offset += $frame_bytesvolume;
                    $parsed_frame['peakvolume']['bass'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesvolume));
                    $frame_offset += $frame_bytesvolume;
                }
            }
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 4) && ($parsed_frame['frame_name'] == 'EQU2')) { 

            
            
            

            
            
            
            
            
            
            

            $frame_offset = 0;
            $frame_interpolationmethod = ord($parsed_frame['data']{$frame_offset++});
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_id_string = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_id_string) === 0) {
                $frame_id_string = '';
            }
            $parsed_frame['description'] = $frame_id_string;
            $frame_remaining_data = substr($parsed_frame['data'], $frame_terminator_pos + strlen("\x00"));
            while (strlen($frame_remaining_data)) {
                $frame_frequency = getid3_lib::BigEndian2Int(substr($frame_remaining_data, 0, 2)) / 2;
                $parsed_frame['data'][$frame_frequency] = getid3_lib::BigEndian2Int(substr($frame_remaining_data, 2, 2), true);
                $frame_remaining_data = substr($frame_remaining_data, 4);
            }
            $parsed_frame['interpolationmethod'] = $frame_interpolationmethod;
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version == 3) && ($parsed_frame['frame_name'] == 'EQUA')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'EQU'))) {     

            
            

            
            
            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['adjustmentbits'] = $parsed_frame['data']{$frame_offset++};
            $frame_adjustment_bytes = ceil($parsed_frame['adjustmentbits'] / 8);

            $frame_remaining_data = (string)substr($parsed_frame['data'], $frame_offset);
            while (strlen($frame_remaining_data) > 0) {
                $frame_frequencystr = getid3_lib::BigEndian2Bin(substr($frame_remaining_data, 0, 2));
                $frame_incdec    = (bool)substr($frame_frequencystr, 0, 1);
                $frame_frequency = bindec(substr($frame_frequencystr, 1, 15));
                $parsed_frame[$frame_frequency]['incdec'] = $frame_incdec;
                $parsed_frame[$frame_frequency]['adjustment'] = getid3_lib::BigEndian2Int(substr($frame_remaining_data, 2, $frame_adjustment_bytes));
                if ($parsed_frame[$frame_frequency]['incdec'] === false) {
                    $parsed_frame[$frame_frequency]['adjustment'] *= -1;
                }
                $frame_remaining_data = substr($frame_remaining_data, 2 + $frame_adjustment_bytes);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'RVRB')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'REV'))) {     

            
            

            
            
            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['left']  = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;
            $parsed_frame['right'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;
            $parsed_frame['bouncesL']   = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['bouncesR']   = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['feedbackLL'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['feedbackLR'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['feedbackRR'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['feedbackRL'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['premixLR']   = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['premixRL']   = ord($parsed_frame['data']{$frame_offset++});
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'APIC')) ||    
           (($id3v2_major_version == 2)  && ($parsed_frame['frame_name'] == 'PIC'))) {     

            
            
            
            

            
            
            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }

            if ($id3v2_major_version == 2 && strlen($parsed_frame['data']) > $frame_offset) {
                $frame_imagetype = substr($parsed_frame['data'], $frame_offset, 3);
                if (strtolower($frame_imagetype) == 'ima') {
                    
                    
                    $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
                    $frame_mimetype = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
                    if (ord($frame_mimetype) === 0) {
                        $frame_mimetype = '';
                    }
                    $frame_imagetype = strtoupper(str_replace('image/', '', strtolower($frame_mimetype)));
                    if ($frame_imagetype == 'JPEG') {
                        $frame_imagetype = 'JPG';
                    }
                    $frame_offset = $frame_terminator_pos + strlen("\x00");
                } else {
                    $frame_offset += 3;
                }
            }

            if ($id3v2_major_version > 2 && strlen($parsed_frame['data']) > $frame_offset) {
                $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
                $frame_mimetype = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
                if (ord($frame_mimetype) === 0) {
                    $frame_mimetype = '';
                }
                $frame_offset = $frame_terminator_pos + strlen("\x00");
            }

            $frame_picturetype = ord($parsed_frame['data']{$frame_offset++});

            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $parsed_frame['encodingid']       = $frame_text_encoding;
            $parsed_frame['encoding']         = $this->TextEncodingNameLookup($frame_text_encoding);

            if ($id3v2_major_version == 2) {
                $parsed_frame['imagetype']    = $frame_imagetype;
            } else {
                $parsed_frame['mime']         = $frame_mimetype;
            }
            $parsed_frame['picturetypeid']    = $frame_picturetype;
            $parsed_frame['picturetype']      = getid3_id3v2::APICPictureTypeLookup($frame_picturetype);
            $parsed_frame['description']      = $frame_description;
            $parsed_frame['data']             = substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)));

            if ($getid3->option_tags_images) {

                $image_chunk_check = getid3_lib_image_size::get($parsed_frame['data']);
                if (($image_chunk_check[2] >= 1) && ($image_chunk_check[2] <= 3)) {
                    $parsed_frame['image_mime'] = image_type_to_mime_type($image_chunk_check[2]);

                    if ($image_chunk_check[0]) {
                        $parsed_frame['image_width']  = $image_chunk_check[0];
                    }

                    if ($image_chunk_check[1]) {
                        $parsed_frame['image_height'] = $image_chunk_check[1];
                    }

                    $parsed_frame['image_bytes']      = strlen($parsed_frame['data']);
                }
            }

            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'GEOB')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'GEO'))) {     

            
            
            

            
            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_mimetype = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_mimetype) === 0) {
                $frame_mimetype = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_filename = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_filename) === 0) {
                $frame_filename = '';
            }
            $frame_offset = $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));

            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $frame_offset = $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));

            $parsed_frame['objectdata']  = (string)substr($parsed_frame['data'], $frame_offset);
            $parsed_frame['encodingid']  = $frame_text_encoding;
            $parsed_frame['encoding']    = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['mime']        = $frame_mimetype;
            $parsed_frame['filename']    = $frame_filename;
            $parsed_frame['description'] = $frame_description;
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'PCNT')) ||     
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'CNT'))) {      

            
            
            
            

            

            $parsed_frame['data'] = getid3_lib::BigEndian2Int($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'POPM')) ||      
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'POP'))) {       

            
            
            

            
            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_email_address = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_email_address) === 0) {
                $frame_email_address = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");
            $frame_rating = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['data'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset));
            $parsed_frame['email']  = $frame_email_address;
            $parsed_frame['rating'] = $frame_rating;
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'RBUF')) ||     
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'BUF'))) {      

            
            

            
            
            

            $frame_offset = 0;
            $parsed_frame['buffersize'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 3));
            $frame_offset += 3;

            $frame_embeddedinfoflags = getid3_lib::BigEndian2Bin($parsed_frame['data']{$frame_offset++});
            $parsed_frame['flags']['embededinfo'] = (bool)substr($frame_embeddedinfoflags, 7, 1);
            $parsed_frame['nexttagoffset'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'CRM')) { 

            
            
            

            
            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_owner_id = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $parsed_frame['ownerid']     = $frame_owner_id;
            $parsed_frame['data']        = (string)substr($parsed_frame['data'], $frame_offset);
            $parsed_frame['description'] = $frame_description;
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'AENC')) ||      
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'CRA'))) {       

            
            
            

            
            
            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_owner_id = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_owner_id) === 0) {
                $frame_owner_id == '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");
            $parsed_frame['ownerid'] = $frame_owner_id;
            $parsed_frame['previewstart'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;
            $parsed_frame['previewlength'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;
            $parsed_frame['encryptioninfo'] = (string)substr($parsed_frame['data'], $frame_offset);
            unset($parsed_frame['data']);
            return true;
        }


        if ((($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'LINK')) ||    
            (($id3v2_major_version == 2) && ($parsed_frame['frame_name'] == 'LNK'))) {     

            
            
            

            
            
            
            

            $frame_offset = 0;
            if ($id3v2_major_version == 2) {
                $parsed_frame['frameid'] = substr($parsed_frame['data'], $frame_offset, 3);
                $frame_offset += 3;
            } else {
                $parsed_frame['frameid'] = substr($parsed_frame['data'], $frame_offset, 4);
                $frame_offset += 4;
            }

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_url = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_url) === 0) {
                $frame_url = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");
            $parsed_frame['url'] = $frame_url;

            $parsed_frame['additionaldata'] = (string)substr($parsed_frame['data'], $frame_offset);
            if (!empty($parsed_frame['framenameshort']) && $parsed_frame['url']) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = utf8_encode($parsed_frame['url']);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'POSS')) { 

            
            
            
            

            $frame_offset = 0;
            $parsed_frame['timestampformat'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['position']        = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset));
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'USER')) { 

            
            
            

            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $frame_language = substr($parsed_frame['data'], $frame_offset, 3);
            $frame_offset += 3;
            $parsed_frame['language']     = $frame_language;
            $parsed_frame['languagename'] = getid3_id3v2::LanguageLookup($frame_language, false);
            $parsed_frame['encodingid']   = $frame_text_encoding;
            $parsed_frame['encoding']     = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['data']         = (string)substr($parsed_frame['data'], $frame_offset);
            if (!empty($parsed_frame['framenameshort']) && !empty($parsed_frame['data'])) {
                $getid3->info['id3v2']['comments'][$parsed_frame['framenameshort']][] = $getid3->iconv($parsed_frame['encoding'], 'UTF-8', $parsed_frame['data']);
            }
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'OWNE')) { 

            
            

            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }
            $parsed_frame['encodingid'] = $frame_text_encoding;
            $parsed_frame['encoding']   = $this->TextEncodingNameLookup($frame_text_encoding);

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_pricepaid = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $parsed_frame['pricepaid']['currencyid'] = substr($frame_pricepaid, 0, 3);
            $parsed_frame['pricepaid']['currency']   = getid3_id3v2::LookupCurrencyUnits($parsed_frame['pricepaid']['currencyid']);
            $parsed_frame['pricepaid']['value']      = substr($frame_pricepaid, 3);

            $parsed_frame['purchasedate'] = substr($parsed_frame['data'], $frame_offset, 8);
            if (!getid3_id3v2::IsValidDateStampString($parsed_frame['purchasedate'])) {
                $parsed_frame['purchasedateunix'] = gmmktime (0, 0, 0, substr($parsed_frame['purchasedate'], 4, 2), substr($parsed_frame['purchasedate'], 6, 2), substr($parsed_frame['purchasedate'], 0, 4));
            }
            $frame_offset += 8;

            $parsed_frame['seller'] = (string)substr($parsed_frame['data'], $frame_offset);
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'COMR')) { 

            
            
            

            
            
            
            
            
            
            
            
            

            $frame_offset = 0;
            $frame_text_encoding = ord($parsed_frame['data']{$frame_offset++});
            if ((($id3v2_major_version <= 3) && ($frame_text_encoding > 1)) || (($id3v2_major_version == 4) && ($frame_text_encoding > 3))) {
                $getid3->warning('Invalid text encoding byte ('.$frame_text_encoding.') in frame "'.$parsed_frame['frame_name'].'" - defaulting to ISO-8859-1 encoding');
            }

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_price_string = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            $frame_offset = $frame_terminator_pos + strlen("\x00");
            $frame_rawpricearray = explode('/', $frame_price_string);
            foreach ($frame_rawpricearray as $key => $val) {
                $frame_currencyid = substr($val, 0, 3);
                $parsed_frame['price'][$frame_currencyid]['currency'] = getid3_id3v2::LookupCurrencyUnits($frame_currencyid);
                $parsed_frame['price'][$frame_currencyid]['value']    = substr($val, 3);
            }

            $frame_date_string = substr($parsed_frame['data'], $frame_offset, 8);
            $frame_offset += 8;

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_contacturl = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $frame_received_as_id = ord($parsed_frame['data']{$frame_offset++});

            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }

            $frame_sellername = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_sellername) === 0) {
                $frame_sellername = '';
            }

            $frame_offset = $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));

            $frame_terminator_pos = @strpos($parsed_frame['data'], getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding), $frame_offset);
            if (ord(substr($parsed_frame['data'], $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding)), 1)) === 0) {
                $frame_terminator_pos++; 
            }

            $frame_description = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_description) === 0) {
                $frame_description = '';
            }

            $frame_offset = $frame_terminator_pos + strlen(getid3_id3v2::TextEncodingTerminatorLookup($frame_text_encoding));

            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_mimetype = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $frame_sellerlogo = substr($parsed_frame['data'], $frame_offset);

            $parsed_frame['encodingid']      = $frame_text_encoding;
            $parsed_frame['encoding']        = $this->TextEncodingNameLookup($frame_text_encoding);

            $parsed_frame['pricevaliduntil'] = $frame_date_string;
            $parsed_frame['contacturl']      = $frame_contacturl;
            $parsed_frame['receivedasid']    = $frame_received_as_id;
            $parsed_frame['receivedas']      = getid3_id3v2::COMRReceivedAsLookup($frame_received_as_id);
            $parsed_frame['sellername']      = $frame_sellername;
            $parsed_frame['description']     = $frame_description;
            $parsed_frame['mime']            = $frame_mimetype;
            $parsed_frame['logo']            = $frame_sellerlogo;
            unset($parsed_frame['data']);
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'ENCR')) { 

            
            
            
            

            
            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_owner_id = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_owner_id) === 0) {
                $frame_owner_id = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $parsed_frame['ownerid']      = $frame_owner_id;
            $parsed_frame['methodsymbol'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['data']         = (string)substr($parsed_frame['data'], $frame_offset);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'GRID')) { 

            
            
            
            

            
            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_owner_id = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_owner_id) === 0) {
                $frame_owner_id = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $parsed_frame['ownerid']       = $frame_owner_id;
            $parsed_frame['groupsymbol']   = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['data']          = (string)substr($parsed_frame['data'], $frame_offset);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'PRIV')) { 

            
            
            

            
            

            $frame_offset = 0;
            $frame_terminator_pos = @strpos($parsed_frame['data'], "\x00", $frame_offset);
            $frame_owner_id = substr($parsed_frame['data'], $frame_offset, $frame_terminator_pos - $frame_offset);
            if (ord($frame_owner_id) === 0) {
                $frame_owner_id = '';
            }
            $frame_offset = $frame_terminator_pos + strlen("\x00");

            $parsed_frame['ownerid'] = $frame_owner_id;
            $parsed_frame['data']    = (string)substr($parsed_frame['data'], $frame_offset);
            return true;
        }


        if (($id3v2_major_version >= 4) && ($parsed_frame['frame_name'] == 'SIGN')) { 

            
            
            

            
            

            $frame_offset = 0;
            $parsed_frame['groupsymbol'] = ord($parsed_frame['data']{$frame_offset++});
            $parsed_frame['data']        = (string)substr($parsed_frame['data'], $frame_offset);
            return true;
        }


        if (($id3v2_major_version >= 4) && ($parsed_frame['frame_name'] == 'SEEK')) { 

            
            

            

            $frame_offset = 0;
            $parsed_frame['data'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
            return true;
        }


        if (($id3v2_major_version >= 4) && ($parsed_frame['frame_name'] == 'ASPI')) { 

            
            

            
            
            
            
            
            

            $frame_offset = 0;
            $parsed_frame['datastart'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
            $frame_offset += 4;
            $parsed_frame['indexeddatalength'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
            $frame_offset += 4;
            $parsed_frame['indexpoints'] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;
            $parsed_frame['bitsperpoint'] = ord($parsed_frame['data']{$frame_offset++});
            $frame_bytesperpoint = ceil($parsed_frame['bitsperpoint'] / 8);
            for ($i = 0; $i < $frame_indexpoints; $i++) {
                $parsed_frame['indexes'][$i] = getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, $frame_bytesperpoint));
                $frame_offset += $frame_bytesperpoint;
            }
            unset($parsed_frame['data']);
            return true;
        }


        if (($id3v2_major_version >= 3) && ($parsed_frame['frame_name'] == 'RGAD')) { 

            
            
            

            
            
            
            
            
            
            

            $frame_offset = 0;

            $parsed_frame['peakamplitude'] = (float)getid3_lib::BigEndian2Int(substr($parsed_frame['data'], $frame_offset, 4));
            $frame_offset += 4;

            $rg_track_adjustment = decbin(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;

            $rg_album_adjustment = decbin(substr($parsed_frame['data'], $frame_offset, 2));
            $frame_offset += 2;

            $parsed_frame['raw']['track']['name']       = bindec(substr($rg_track_adjustment, 0, 3));
            $parsed_frame['raw']['track']['originator'] = bindec(substr($rg_track_adjustment, 3, 3));
            $parsed_frame['raw']['track']['signbit']    = bindec($rg_track_adjustment[6]);
            $parsed_frame['raw']['track']['adjustment'] = bindec(substr($rg_track_adjustment, 7, 9));
            $parsed_frame['raw']['album']['name']       = bindec(substr($rg_album_adjustment, 0, 3));
            $parsed_frame['raw']['album']['originator'] = bindec(substr($rg_album_adjustment, 3, 3));
            $parsed_frame['raw']['album']['signbit']    = bindec($rg_album_adjustment[6]);
            $parsed_frame['raw']['album']['adjustment'] = bindec(substr($rg_album_adjustment, 7, 9));
            $parsed_frame['track']['name']              = getid3_lib_replaygain::NameLookup($parsed_frame['raw']['track']['name']);
            $parsed_frame['track']['originator']        = getid3_lib_replaygain::OriginatorLookup($parsed_frame['raw']['track']['originator']);
            $parsed_frame['track']['adjustment']        = getid3_lib_replaygain::AdjustmentLookup($parsed_frame['raw']['track']['adjustment'], $parsed_frame['raw']['track']['signbit']);
            $parsed_frame['album']['name']              = getid3_lib_replaygain::NameLookup($parsed_frame['raw']['album']['name']);
            $parsed_frame['album']['originator']        = getid3_lib_replaygain::OriginatorLookup($parsed_frame['raw']['album']['originator']);
            $parsed_frame['album']['adjustment']        = getid3_lib_replaygain::AdjustmentLookup($parsed_frame['raw']['album']['adjustment'], $parsed_frame['raw']['album']['signbit']);

            $getid3->info['replay_gain']['track']['peak']       = $parsed_frame['peakamplitude'];
            $getid3->info['replay_gain']['track']['originator'] = $parsed_frame['track']['originator'];
            $getid3->info['replay_gain']['track']['adjustment'] = $parsed_frame['track']['adjustment'];
            $getid3->info['replay_gain']['album']['originator'] = $parsed_frame['album']['originator'];
            $getid3->info['replay_gain']['album']['adjustment'] = $parsed_frame['album']['adjustment'];

            unset($parsed_frame['data']);
            return true;
        }

        return true;
    }



    private function TextEncodingNameLookup($encoding) {

        
        if (!$encoding) {
            return $this->getid3->encoding_id3v2;
        }

        
        static $lookup = array (
            0   => 'ISO-8859-1',
            1   => 'UTF-16',
            2   => 'UTF-16BE',
            3   => 'UTF-8',
            255 => 'UTF-16BE'
        );

        return (isset($lookup[$encoding]) ? $lookup[$encoding] : 'ISO-8859-1');
    }



    public static function ParseID3v2GenreString($genre_string) {

        
        
        

        $genre_string = trim($genre_string);
        $return_array = array ();
        if (strpos($genre_string, "\x00") !== false) {
            $unprocessed = trim($genre_string); 
            $genre_string = '';
            while (strpos($unprocessed, "\x00") !== false) {
				
				$end_pos = strpos($unprocessed, "\x00");
				$genre_string .= '('.substr($unprocessed, 0, $end_pos).')';
				$unprocessed = substr($unprocessed, $end_pos + 1);
            }
            unset($unprocessed);
        } elseif (preg_match('#^([0-9]+|CR|RX)$#i', $genre_string)) {
        	
			$genre_string = '('.$genre_string.')';
        }
        if (getid3_id3v1::LookupGenreID($genre_string)) {

            $return_array['genre'][] = $genre_string;

        } else {

			if ((strpos($genre_string, '(') !== false) && (strpos($genre_string, ')') !== false)) {
				do {
	                $start_pos = strpos($genre_string, '(');
	                $end_pos   = strpos($genre_string, ')');
	                if (substr($genre_string, $start_pos + 1, 1) == '(') {
	                    $genre_string = substr($genre_string, 0, $start_pos).substr($genre_string, $start_pos + 1);
	                    $end_pos--;
	                }
	                $element      = substr($genre_string, $start_pos + 1, $end_pos - ($start_pos + 1));
	                $genre_string = substr($genre_string, 0, $start_pos).substr($genre_string, $end_pos + 1);

	                if (getid3_id3v1::LookupGenreName($element)) { 

	                    if (empty($return_array['genre']) || !in_array(getid3_id3v1::LookupGenreName($element), $return_array['genre'])) { 
	                        $return_array['genre'][] = getid3_id3v1::LookupGenreName($element);
	                    }
	                } else {

	                    if (empty($return_array['genre']) || !in_array($element, $return_array['genre'])) { 
	                        $return_array['genre'][] = $element;
	                    }
	                }
	            } while ($end_pos > $start_pos);
	        }
        }
        if ($genre_string) {
            if (empty($return_array['genre']) || !in_array($genre_string, $return_array['genre'])) { 
                $return_array['genre'][] = $genre_string;
            }
        }

        return $return_array;
    }



    public static function LookupCurrencyUnits($currency_id) {

        static $lookup = array (
            'AED' => 'Dirhams',
            'AFA' => 'Afghanis',
            'ALL' => 'Leke',
            'AMD' => 'Drams',
            'ANG' => 'Guilders',
            'AOA' => 'Kwanza',
            'ARS' => 'Pesos',
            'ATS' => 'Schillings',
            'AUD' => 'Dollars',
            'AWG' => 'Guilders',
            'AZM' => 'Manats',
            'BAM' => 'Convertible Marka',
            'BBD' => 'Dollars',
            'BDT' => 'Taka',
            'BEF' => 'Francs',
            'BGL' => 'Leva',
            'BHD' => 'Dinars',
            'BIF' => 'Francs',
            'BMD' => 'Dollars',
            'BND' => 'Dollars',
            'BOB' => 'Bolivianos',
            'BRL' => 'Brazil Real',
            'BSD' => 'Dollars',
            'BTN' => 'Ngultrum',
            'BWP' => 'Pulas',
            'BYR' => 'Rubles',
            'BZD' => 'Dollars',
            'CAD' => 'Dollars',
            'CDF' => 'Congolese Francs',
            'CHF' => 'Francs',
            'CLP' => 'Pesos',
            'CNY' => 'Yuan Renminbi',
            'COP' => 'Pesos',
            'CRC' => 'Colones',
            'CUP' => 'Pesos',
            'CVE' => 'Escudos',
            'CYP' => 'Pounds',
            'CZK' => 'Koruny',
            'DEM' => 'Deutsche Marks',
            'DJF' => 'Francs',
            'DKK' => 'Kroner',
            'DOP' => 'Pesos',
            'DZD' => 'Algeria Dinars',
            'EEK' => 'Krooni',
            'EGP' => 'Pounds',
            'ERN' => 'Nakfa',
            'ESP' => 'Pesetas',
            'ETB' => 'Birr',
            'EUR' => 'Euro',
            'FIM' => 'Markkaa',
            'FJD' => 'Dollars',
            'FKP' => 'Pounds',
            'FRF' => 'Francs',
            'GBP' => 'Pounds',
            'GEL' => 'Lari',
            'GGP' => 'Pounds',
            'GHC' => 'Cedis',
            'GIP' => 'Pounds',
            'GMD' => 'Dalasi',
            'GNF' => 'Francs',
            'GRD' => 'Drachmae',
            'GTQ' => 'Quetzales',
            'GYD' => 'Dollars',
            'HKD' => 'Dollars',
            'HNL' => 'Lempiras',
            'HRK' => 'Kuna',
            'HTG' => 'Gourdes',
            'HUF' => 'Forints',
            'IDR' => 'Rupiahs',
            'IEP' => 'Pounds',
            'ILS' => 'New Shekels',
            'IMP' => 'Pounds',
            'INR' => 'Rupees',
            'IQD' => 'Dinars',
            'IRR' => 'Rials',
            'ISK' => 'Kronur',
            'ITL' => 'Lire',
            'JEP' => 'Pounds',
            'JMD' => 'Dollars',
            'JOD' => 'Dinars',
            'JPY' => 'Yen',
            'KES' => 'Shillings',
            'KGS' => 'Soms',
            'KHR' => 'Riels',
            'KMF' => 'Francs',
            'KPW' => 'Won',
            'KWD' => 'Dinars',
            'KYD' => 'Dollars',
            'KZT' => 'Tenge',
            'LAK' => 'Kips',
            'LBP' => 'Pounds',
            'LKR' => 'Rupees',
            'LRD' => 'Dollars',
            'LSL' => 'Maloti',
            'LTL' => 'Litai',
            'LUF' => 'Francs',
            'LVL' => 'Lati',
            'LYD' => 'Dinars',
            'MAD' => 'Dirhams',
            'MDL' => 'Lei',
            'MGF' => 'Malagasy Francs',
            'MKD' => 'Denars',
            'MMK' => 'Kyats',
            'MNT' => 'Tugriks',
            'MOP' => 'Patacas',
            'MRO' => 'Ouguiyas',
            'MTL' => 'Liri',
            'MUR' => 'Rupees',
            'MVR' => 'Rufiyaa',
            'MWK' => 'Kwachas',
            'MXN' => 'Pesos',
            'MYR' => 'Ringgits',
            'MZM' => 'Meticais',
            'NAD' => 'Dollars',
            'NGN' => 'Nairas',
            'NIO' => 'Gold Cordobas',
            'NLG' => 'Guilders',
            'NOK' => 'Krone',
            'NPR' => 'Nepal Rupees',
            'NZD' => 'Dollars',
            'OMR' => 'Rials',
            'PAB' => 'Balboa',
            'PEN' => 'Nuevos Soles',
            'PGK' => 'Kina',
            'PHP' => 'Pesos',
            'PKR' => 'Rupees',
            'PLN' => 'Zlotych',
            'PTE' => 'Escudos',
            'PYG' => 'Guarani',
            'QAR' => 'Rials',
            'ROL' => 'Lei',
            'RUR' => 'Rubles',
            'RWF' => 'Rwanda Francs',
            'SAR' => 'Riyals',
            'SBD' => 'Dollars',
            'SCR' => 'Rupees',
            'SDD' => 'Dinars',
            'SEK' => 'Kronor',
            'SGD' => 'Dollars',
            'SHP' => 'Pounds',
            'SIT' => 'Tolars',
            'SKK' => 'Koruny',
            'SLL' => 'Leones',
            'SOS' => 'Shillings',
            'SPL' => 'Luigini',
            'SRG' => 'Guilders',
            'STD' => 'Dobras',
            'SVC' => 'Colones',
            'SYP' => 'Pounds',
            'SZL' => 'Emalangeni',
            'THB' => 'Baht',
            'TJR' => 'Rubles',
            'TMM' => 'Manats',
            'TND' => 'Dinars',
            'TOP' => 'Pa\'anga',
            'TRL' => 'Liras',
            'TTD' => 'Dollars',
            'TVD' => 'Tuvalu Dollars',
            'TWD' => 'New Dollars',
            'TZS' => 'Shillings',
            'UAH' => 'Hryvnia',
            'UGX' => 'Shillings',
            'USD' => 'Dollars',
            'UYU' => 'Pesos',
            'UZS' => 'Sums',
            'VAL' => 'Lire',
            'VEB' => 'Bolivares',
            'VND' => 'Dong',
            'VUV' => 'Vatu',
            'WST' => 'Tala',
            'XAF' => 'Francs',
            'XAG' => 'Ounces',
            'XAU' => 'Ounces',
            'XCD' => 'Dollars',
            'XDR' => 'Special Drawing Rights',
            'XPD' => 'Ounces',
            'XPF' => 'Francs',
            'XPT' => 'Ounces',
            'YER' => 'Rials',
            'YUM' => 'New Dinars',
            'ZAR' => 'Rand',
            'ZMK' => 'Kwacha',
            'ZWD' => 'Zimbabwe Dollars'
        );

        return @$lookup[$currency_id];
    }



    public static function LookupCurrencyCountry($currency_id) {

        static $lookup = array (
            'AED' => 'United Arab Emirates',
            'AFA' => 'Afghanistan',
            'ALL' => 'Albania',
            'AMD' => 'Armenia',
            'ANG' => 'Netherlands Antilles',
            'AOA' => 'Angola',
            'ARS' => 'Argentina',
            'ATS' => 'Austria',
            'AUD' => 'Australia',
            'AWG' => 'Aruba',
            'AZM' => 'Azerbaijan',
            'BAM' => 'Bosnia and Herzegovina',
            'BBD' => 'Barbados',
            'BDT' => 'Bangladesh',
            'BEF' => 'Belgium',
            'BGL' => 'Bulgaria',
            'BHD' => 'Bahrain',
            'BIF' => 'Burundi',
            'BMD' => 'Bermuda',
            'BND' => 'Brunei Darussalam',
            'BOB' => 'Bolivia',
            'BRL' => 'Brazil',
            'BSD' => 'Bahamas',
            'BTN' => 'Bhutan',
            'BWP' => 'Botswana',
            'BYR' => 'Belarus',
            'BZD' => 'Belize',
            'CAD' => 'Canada',
            'CDF' => 'Congo/Kinshasa',
            'CHF' => 'Switzerland',
            'CLP' => 'Chile',
            'CNY' => 'China',
            'COP' => 'Colombia',
            'CRC' => 'Costa Rica',
            'CUP' => 'Cuba',
            'CVE' => 'Cape Verde',
            'CYP' => 'Cyprus',
            'CZK' => 'Czech Republic',
            'DEM' => 'Germany',
            'DJF' => 'Djibouti',
            'DKK' => 'Denmark',
            'DOP' => 'Dominican Republic',
            'DZD' => 'Algeria',
            'EEK' => 'Estonia',
            'EGP' => 'Egypt',
            'ERN' => 'Eritrea',
            'ESP' => 'Spain',
            'ETB' => 'Ethiopia',
            'EUR' => 'Euro Member Countries',
            'FIM' => 'Finland',
            'FJD' => 'Fiji',
            'FKP' => 'Falkland Islands (Malvinas)',
            'FRF' => 'France',
            'GBP' => 'United Kingdom',
            'GEL' => 'Georgia',
            'GGP' => 'Guernsey',
            'GHC' => 'Ghana',
            'GIP' => 'Gibraltar',
            'GMD' => 'Gambia',
            'GNF' => 'Guinea',
            'GRD' => 'Greece',
            'GTQ' => 'Guatemala',
            'GYD' => 'Guyana',
            'HKD' => 'Hong Kong',
            'HNL' => 'Honduras',
            'HRK' => 'Croatia',
            'HTG' => 'Haiti',
            'HUF' => 'Hungary',
            'IDR' => 'Indonesia',
            'IEP' => 'Ireland (Eire)',
            'ILS' => 'Israel',
            'IMP' => 'Isle of Man',
            'INR' => 'India',
            'IQD' => 'Iraq',
            'IRR' => 'Iran',
            'ISK' => 'Iceland',
            'ITL' => 'Italy',
            'JEP' => 'Jersey',
            'JMD' => 'Jamaica',
            'JOD' => 'Jordan',
            'JPY' => 'Japan',
            'KES' => 'Kenya',
            'KGS' => 'Kyrgyzstan',
            'KHR' => 'Cambodia',
            'KMF' => 'Comoros',
            'KPW' => 'Korea',
            'KWD' => 'Kuwait',
            'KYD' => 'Cayman Islands',
            'KZT' => 'Kazakstan',
            'LAK' => 'Laos',
            'LBP' => 'Lebanon',
            'LKR' => 'Sri Lanka',
            'LRD' => 'Liberia',
            'LSL' => 'Lesotho',
            'LTL' => 'Lithuania',
            'LUF' => 'Luxembourg',
            'LVL' => 'Latvia',
            'LYD' => 'Libya',
            'MAD' => 'Morocco',
            'MDL' => 'Moldova',
            'MGF' => 'Madagascar',
            'MKD' => 'Macedonia',
            'MMK' => 'Myanmar (Burma)',
            'MNT' => 'Mongolia',
            'MOP' => 'Macau',
            'MRO' => 'Mauritania',
            'MTL' => 'Malta',
            'MUR' => 'Mauritius',
            'MVR' => 'Maldives (Maldive Islands)',
            'MWK' => 'Malawi',
            'MXN' => 'Mexico',
            'MYR' => 'Malaysia',
            'MZM' => 'Mozambique',
            'NAD' => 'Namibia',
            'NGN' => 'Nigeria',
            'NIO' => 'Nicaragua',
            'NLG' => 'Netherlands (Holland)',
            'NOK' => 'Norway',
            'NPR' => 'Nepal',
            'NZD' => 'New Zealand',
            'OMR' => 'Oman',
            'PAB' => 'Panama',
            'PEN' => 'Peru',
            'PGK' => 'Papua New Guinea',
            'PHP' => 'Philippines',
            'PKR' => 'Pakistan',
            'PLN' => 'Poland',
            'PTE' => 'Portugal',
            'PYG' => 'Paraguay',
            'QAR' => 'Qatar',
            'ROL' => 'Romania',
            'RUR' => 'Russia',
            'RWF' => 'Rwanda',
            'SAR' => 'Saudi Arabia',
            'SBD' => 'Solomon Islands',
            'SCR' => 'Seychelles',
            'SDD' => 'Sudan',
            'SEK' => 'Sweden',
            'SGD' => 'Singapore',
            'SHP' => 'Saint Helena',
            'SIT' => 'Slovenia',
            'SKK' => 'Slovakia',
            'SLL' => 'Sierra Leone',
            'SOS' => 'Somalia',
            'SPL' => 'Seborga',
            'SRG' => 'Suriname',
            'STD' => 'São Tome and Principe',
            'SVC' => 'El Salvador',
            'SYP' => 'Syria',
            'SZL' => 'Swaziland',
            'THB' => 'Thailand',
            'TJR' => 'Tajikistan',
            'TMM' => 'Turkmenistan',
            'TND' => 'Tunisia',
            'TOP' => 'Tonga',
            'TRL' => 'Turkey',
            'TTD' => 'Trinidad and Tobago',
            'TVD' => 'Tuvalu',
            'TWD' => 'Taiwan',
            'TZS' => 'Tanzania',
            'UAH' => 'Ukraine',
            'UGX' => 'Uganda',
            'USD' => 'United States of America',
            'UYU' => 'Uruguay',
            'UZS' => 'Uzbekistan',
            'VAL' => 'Vatican City',
            'VEB' => 'Venezuela',
            'VND' => 'Viet Nam',
            'VUV' => 'Vanuatu',
            'WST' => 'Samoa',
            'XAF' => 'Communauté Financière Africaine',
            'XAG' => 'Silver',
            'XAU' => 'Gold',
            'XCD' => 'East Caribbean',
            'XDR' => 'International Monetary Fund',
            'XPD' => 'Palladium',
            'XPF' => 'Comptoirs Français du Pacifique',
            'XPT' => 'Platinum',
            'YER' => 'Yemen',
            'YUM' => 'Yugoslavia',
            'ZAR' => 'South Africa',
            'ZMK' => 'Zambia',
            'ZWD' => 'Zimbabwe'
        );

        return @$lookup[$currency_id];
    }



    public static function LanguageLookup($language_code, $case_sensitive=false) {

        if (!$case_sensitive) {
            $language_code = strtolower($language_code);
        }

        
        
        
        
        
        


        

        static $lookup = array (
            'XXX' => 'unknown',
            'xxx' => 'unknown',
            'aar' => 'Afar',
            'abk' => 'Abkhazian',
            'ace' => 'Achinese',
            'ach' => 'Acoli',
            'ada' => 'Adangme',
            'afa' => 'Afro-Asiatic (Other)',
            'afh' => 'Afrihili',
            'afr' => 'Afrikaans',
            'aka' => 'Akan',
            'akk' => 'Akkadian',
            'alb' => 'Albanian',
            'ale' => 'Aleut',
            'alg' => 'Algonquian Languages',
            'amh' => 'Amharic',
            'ang' => 'English, Old (ca. 450-1100)',
            'apa' => 'Apache Languages',
            'ara' => 'Arabic',
            'arc' => 'Aramaic',
            'arm' => 'Armenian',
            'arn' => 'Araucanian',
            'arp' => 'Arapaho',
            'art' => 'Artificial (Other)',
            'arw' => 'Arawak',
            'asm' => 'Assamese',
            'ath' => 'Athapascan Languages',
            'ava' => 'Avaric',
            'ave' => 'Avestan',
            'awa' => 'Awadhi',
            'aym' => 'Aymara',
            'aze' => 'Azerbaijani',
            'bad' => 'Banda',
            'bai' => 'Bamileke Languages',
            'bak' => 'Bashkir',
            'bal' => 'Baluchi',
            'bam' => 'Bambara',
            'ban' => 'Balinese',
            'baq' => 'Basque',
            'bas' => 'Basa',
            'bat' => 'Baltic (Other)',
            'bej' => 'Beja',
            'bel' => 'Byelorussian',
            'bem' => 'Bemba',
            'ben' => 'Bengali',
            'ber' => 'Berber (Other)',
            'bho' => 'Bhojpuri',
            'bih' => 'Bihari',
            'bik' => 'Bikol',
            'bin' => 'Bini',
            'bis' => 'Bislama',
            'bla' => 'Siksika',
            'bnt' => 'Bantu (Other)',
            'bod' => 'Tibetan',
            'bra' => 'Braj',
            'bre' => 'Breton',
            'bua' => 'Buriat',
            'bug' => 'Buginese',
            'bul' => 'Bulgarian',
            'bur' => 'Burmese',
            'cad' => 'Caddo',
            'cai' => 'Central American Indian (Other)',
            'car' => 'Carib',
            'cat' => 'Catalan',
            'cau' => 'Caucasian (Other)',
            'ceb' => 'Cebuano',
            'cel' => 'Celtic (Other)',
            'ces' => 'Czech',
            'cha' => 'Chamorro',
            'chb' => 'Chibcha',
            'che' => 'Chechen',
            'chg' => 'Chagatai',
            'chi' => 'Chinese',
            'chm' => 'Mari',
            'chn' => 'Chinook jargon',
            'cho' => 'Choctaw',
            'chr' => 'Cherokee',
            'chu' => 'Church Slavic',
            'chv' => 'Chuvash',
            'chy' => 'Cheyenne',
            'cop' => 'Coptic',
            'cor' => 'Cornish',
            'cos' => 'Corsican',
            'cpe' => 'Creoles and Pidgins, English-based (Other)',
            'cpf' => 'Creoles and Pidgins, French-based (Other)',
            'cpp' => 'Creoles and Pidgins, Portuguese-based (Other)',
            'cre' => 'Cree',
            'crp' => 'Creoles and Pidgins (Other)',
            'cus' => 'Cushitic (Other)',
            'cym' => 'Welsh',
            'cze' => 'Czech',
            'dak' => 'Dakota',
            'dan' => 'Danish',
            'del' => 'Delaware',
            'deu' => 'German',
            'din' => 'Dinka',
            'div' => 'Divehi',
            'doi' => 'Dogri',
            'dra' => 'Dravidian (Other)',
            'dua' => 'Duala',
            'dum' => 'Dutch, Middle (ca. 1050-1350)',
            'dut' => 'Dutch',
            'dyu' => 'Dyula',
            'dzo' => 'Dzongkha',
            'efi' => 'Efik',
            'egy' => 'Egyptian (Ancient)',
            'eka' => 'Ekajuk',
            'ell' => 'Greek, Modern (1453-)',
            'elx' => 'Elamite',
            'eng' => 'English',
            'enm' => 'English, Middle (ca. 1100-1500)',
            'epo' => 'Esperanto',
            'esk' => 'Eskimo (Other)',
            'esl' => 'Spanish',
            'est' => 'Estonian',
            'eus' => 'Basque',
            'ewe' => 'Ewe',
            'ewo' => 'Ewondo',
            'fan' => 'Fang',
            'fao' => 'Faroese',
            'fas' => 'Persian',
            'fat' => 'Fanti',
            'fij' => 'Fijian',
            'fin' => 'Finnish',
            'fiu' => 'Finno-Ugrian (Other)',
            'fon' => 'Fon',
            'fra' => 'French',
            'fre' => 'French',
            'frm' => 'French, Middle (ca. 1400-1600)',
            'fro' => 'French, Old (842- ca. 1400)',
            'fry' => 'Frisian',
            'ful' => 'Fulah',
            'gaa' => 'Ga',
            'gae' => 'Gaelic (Scots)',
            'gai' => 'Irish',
            'gay' => 'Gayo',
            'gdh' => 'Gaelic (Scots)',
            'gem' => 'Germanic (Other)',
            'geo' => 'Georgian',
            'ger' => 'German',
            'gez' => 'Geez',
            'gil' => 'Gilbertese',
            'glg' => 'Gallegan',
            'gmh' => 'German, Middle High (ca. 1050-1500)',
            'goh' => 'German, Old High (ca. 750-1050)',
            'gon' => 'Gondi',
            'got' => 'Gothic',
            'grb' => 'Grebo',
            'grc' => 'Greek, Ancient (to 1453)',
            'gre' => 'Greek, Modern (1453-)',
            'grn' => 'Guarani',
            'guj' => 'Gujarati',
            'hai' => 'Haida',
            'hau' => 'Hausa',
            'haw' => 'Hawaiian',
            'heb' => 'Hebrew',
            'her' => 'Herero',
            'hil' => 'Hiligaynon',
            'him' => 'Himachali',
            'hin' => 'Hindi',
            'hmo' => 'Hiri Motu',
            'hun' => 'Hungarian',
            'hup' => 'Hupa',
            'hye' => 'Armenian',
            'iba' => 'Iban',
            'ibo' => 'Igbo',
            'ice' => 'Icelandic',
            'ijo' => 'Ijo',
            'iku' => 'Inuktitut',
            'ilo' => 'Iloko',
            'ina' => 'Interlingua (International Auxiliary language Association)',
            'inc' => 'Indic (Other)',
            'ind' => 'Indonesian',
            'ine' => 'Indo-European (Other)',
            'ine' => 'Interlingue',
            'ipk' => 'Inupiak',
            'ira' => 'Iranian (Other)',
            'iri' => 'Irish',
            'iro' => 'Iroquoian uages',
            'isl' => 'Icelandic',
            'ita' => 'Italian',
            'jav' => 'Javanese',
            'jaw' => 'Javanese',
            'jpn' => 'Japanese',
            'jpr' => 'Judeo-Persian',
            'jrb' => 'Judeo-Arabic',
            'kaa' => 'Kara-Kalpak',
            'kab' => 'Kabyle',
            'kac' => 'Kachin',
            'kal' => 'Greenlandic',
            'kam' => 'Kamba',
            'kan' => 'Kannada',
            'kar' => 'Karen',
            'kas' => 'Kashmiri',
            'kat' => 'Georgian',
            'kau' => 'Kanuri',
            'kaw' => 'Kawi',
            'kaz' => 'Kazakh',
            'kha' => 'Khasi',
            'khi' => 'Khoisan (Other)',
            'khm' => 'Khmer',
            'kho' => 'Khotanese',
            'kik' => 'Kikuyu',
            'kin' => 'Kinyarwanda',
            'kir' => 'Kirghiz',
            'kok' => 'Konkani',
            'kom' => 'Komi',
            'kon' => 'Kongo',
            'kor' => 'Korean',
            'kpe' => 'Kpelle',
            'kro' => 'Kru',
            'kru' => 'Kurukh',
            'kua' => 'Kuanyama',
            'kum' => 'Kumyk',
            'kur' => 'Kurdish',
            'kus' => 'Kusaie',
            'kut' => 'Kutenai',
            'lad' => 'Ladino',
            'lah' => 'Lahnda',
            'lam' => 'Lamba',
            'lao' => 'Lao',
            'lat' => 'Latin',
            'lav' => 'Latvian',
            'lez' => 'Lezghian',
            'lin' => 'Lingala',
            'lit' => 'Lithuanian',
            'lol' => 'Mongo',
            'loz' => 'Lozi',
            'ltz' => 'Letzeburgesch',
            'lub' => 'Luba-Katanga',
            'lug' => 'Ganda',
            'lui' => 'Luiseno',
            'lun' => 'Lunda',
            'luo' => 'Luo (Kenya and Tanzania)',
            'mac' => 'Macedonian',
            'mad' => 'Madurese',
            'mag' => 'Magahi',
            'mah' => 'Marshall',
            'mai' => 'Maithili',
            'mak' => 'Macedonian',
            'mak' => 'Makasar',
            'mal' => 'Malayalam',
            'man' => 'Mandingo',
            'mao' => 'Maori',
            'map' => 'Austronesian (Other)',
            'mar' => 'Marathi',
            'mas' => 'Masai',
            'max' => 'Manx',
            'may' => 'Malay',
            'men' => 'Mende',
            'mga' => 'Irish, Middle (900 - 1200)',
            'mic' => 'Micmac',
            'min' => 'Minangkabau',
            'mis' => 'Miscellaneous (Other)',
            'mkh' => 'Mon-Kmer (Other)',
            'mlg' => 'Malagasy',
            'mlt' => 'Maltese',
            'mni' => 'Manipuri',
            'mno' => 'Manobo Languages',
            'moh' => 'Mohawk',
            'mol' => 'Moldavian',
            'mon' => 'Mongolian',
            'mos' => 'Mossi',
            'mri' => 'Maori',
            'msa' => 'Malay',
            'mul' => 'Multiple Languages',
            'mun' => 'Munda Languages',
            'mus' => 'Creek',
            'mwr' => 'Marwari',
            'mya' => 'Burmese',
            'myn' => 'Mayan Languages',
            'nah' => 'Aztec',
            'nai' => 'North American Indian (Other)',
            'nau' => 'Nauru',
            'nav' => 'Navajo',
            'nbl' => 'Ndebele, South',
            'nde' => 'Ndebele, North',
            'ndo' => 'Ndongo',
            'nep' => 'Nepali',
            'new' => 'Newari',
            'nic' => 'Niger-Kordofanian (Other)',
            'niu' => 'Niuean',
            'nla' => 'Dutch',
            'nno' => 'Norwegian (Nynorsk)',
            'non' => 'Norse, Old',
            'nor' => 'Norwegian',
            'nso' => 'Sotho, Northern',
            'nub' => 'Nubian Languages',
            'nya' => 'Nyanja',
            'nym' => 'Nyamwezi',
            'nyn' => 'Nyankole',
            'nyo' => 'Nyoro',
            'nzi' => 'Nzima',
            'oci' => 'Langue d\'Oc (post 1500)',
            'oji' => 'Ojibwa',
            'ori' => 'Oriya',
            'orm' => 'Oromo',
            'osa' => 'Osage',
            'oss' => 'Ossetic',
            'ota' => 'Turkish, Ottoman (1500 - 1928)',
            'oto' => 'Otomian Languages',
            'paa' => 'Papuan-Australian (Other)',
            'pag' => 'Pangasinan',
            'pal' => 'Pahlavi',
            'pam' => 'Pampanga',
            'pan' => 'Panjabi',
            'pap' => 'Papiamento',
            'pau' => 'Palauan',
            'peo' => 'Persian, Old (ca 600 - 400 B.C.)',
            'per' => 'Persian',
            'phn' => 'Phoenician',
            'pli' => 'Pali',
            'pol' => 'Polish',
            'pon' => 'Ponape',
            'por' => 'Portuguese',
            'pra' => 'Prakrit uages',
            'pro' => 'Provencal, Old (to 1500)',
            'pus' => 'Pushto',
            'que' => 'Quechua',
            'raj' => 'Rajasthani',
            'rar' => 'Rarotongan',
            'roa' => 'Romance (Other)',
            'roh' => 'Rhaeto-Romance',
            'rom' => 'Romany',
            'ron' => 'Romanian',
            'rum' => 'Romanian',
            'run' => 'Rundi',
            'rus' => 'Russian',
            'sad' => 'Sandawe',
            'sag' => 'Sango',
            'sah' => 'Yakut',
            'sai' => 'South American Indian (Other)',
            'sal' => 'Salishan Languages',
            'sam' => 'Samaritan Aramaic',
            'san' => 'Sanskrit',
            'sco' => 'Scots',
            'scr' => 'Serbo-Croatian',
            'sel' => 'Selkup',
            'sem' => 'Semitic (Other)',
            'sga' => 'Irish, Old (to 900)',
            'shn' => 'Shan',
            'sid' => 'Sidamo',
            'sin' => 'Singhalese',
            'sio' => 'Siouan Languages',
            'sit' => 'Sino-Tibetan (Other)',
            'sla' => 'Slavic (Other)',
            'slk' => 'Slovak',
            'slo' => 'Slovak',
            'slv' => 'Slovenian',
            'smi' => 'Sami Languages',
            'smo' => 'Samoan',
            'sna' => 'Shona',
            'snd' => 'Sindhi',
            'sog' => 'Sogdian',
            'som' => 'Somali',
            'son' => 'Songhai',
            'sot' => 'Sotho, Southern',
            'spa' => 'Spanish',
            'sqi' => 'Albanian',
            'srd' => 'Sardinian',
            'srr' => 'Serer',
            'ssa' => 'Nilo-Saharan (Other)',
            'ssw' => 'Siswant',
            'ssw' => 'Swazi',
            'suk' => 'Sukuma',
            'sun' => 'Sudanese',
            'sus' => 'Susu',
            'sux' => 'Sumerian',
            'sve' => 'Swedish',
            'swa' => 'Swahili',
            'swe' => 'Swedish',
            'syr' => 'Syriac',
            'tah' => 'Tahitian',
            'tam' => 'Tamil',
            'tat' => 'Tatar',
            'tel' => 'Telugu',
            'tem' => 'Timne',
            'ter' => 'Tereno',
            'tgk' => 'Tajik',
            'tgl' => 'Tagalog',
            'tha' => 'Thai',
            'tib' => 'Tibetan',
            'tig' => 'Tigre',
            'tir' => 'Tigrinya',
            'tiv' => 'Tivi',
            'tli' => 'Tlingit',
            'tmh' => 'Tamashek',
            'tog' => 'Tonga (Nyasa)',
            'ton' => 'Tonga (Tonga Islands)',
            'tru' => 'Truk',
            'tsi' => 'Tsimshian',
            'tsn' => 'Tswana',
            'tso' => 'Tsonga',
            'tuk' => 'Turkmen',
            'tum' => 'Tumbuka',
            'tur' => 'Turkish',
            'tut' => 'Altaic (Other)',
            'twi' => 'Twi',
            'tyv' => 'Tuvinian',
            'uga' => 'Ugaritic',
            'uig' => 'Uighur',
            'ukr' => 'Ukrainian',
            'umb' => 'Umbundu',
            'und' => 'Undetermined',
            'urd' => 'Urdu',
            'uzb' => 'Uzbek',
            'vai' => 'Vai',
            'ven' => 'Venda',
            'vie' => 'Vietnamese',
            'vol' => 'Volapük',
            'vot' => 'Votic',
            'wak' => 'Wakashan Languages',
            'wal' => 'Walamo',
            'war' => 'Waray',
            'was' => 'Washo',
            'wel' => 'Welsh',
            'wen' => 'Sorbian Languages',
            'wol' => 'Wolof',
            'xho' => 'Xhosa',
            'yao' => 'Yao',
            'yap' => 'Yap',
            'yid' => 'Yiddish',
            'yor' => 'Yoruba',
            'zap' => 'Zapotec',
            'zen' => 'Zenaga',
            'zha' => 'Zhuang',
            'zho' => 'Chinese',
            'zul' => 'Zulu',
            'zun' => 'Zuni'
        );

        return @$lookup[$language_code];
    }



    public static function ETCOEventLookup($index) {

        if (($index >= 0x17) && ($index <= 0xDF)) {
            return 'reserved for future use';
        }
        if (($index >= 0xE0) && ($index <= 0xEF)) {
            return 'not predefined synch 0-F';
        }
        if (($index >= 0xF0) && ($index <= 0xFC)) {
            return 'reserved for future use';
        }

        static $lookup = array (
            0x00 => 'padding (has no meaning)',
            0x01 => 'end of initial silence',
            0x02 => 'intro start',
            0x03 => 'main part start',
            0x04 => 'outro start',
            0x05 => 'outro end',
            0x06 => 'verse start',
            0x07 => 'refrain start',
            0x08 => 'interlude start',
            0x09 => 'theme start',
            0x0A => 'variation start',
            0x0B => 'key change',
            0x0C => 'time change',
            0x0D => 'momentary unwanted noise (Snap, Crackle & Pop)',
            0x0E => 'sustained noise',
            0x0F => 'sustained noise end',
            0x10 => 'intro end',
            0x11 => 'main part end',
            0x12 => 'verse end',
            0x13 => 'refrain end',
            0x14 => 'theme end',
            0x15 => 'profanity',
            0x16 => 'profanity end',
            0xFD => 'audio end (start of silence)',
            0xFE => 'audio file ends',
            0xFF => 'one more byte of events follows'
        );

        return @$lookup[$index];
    }



    public static function SYTLContentTypeLookup($index) {

        static $lookup = array (
            0x00 => 'other',
            0x01 => 'lyrics',
            0x02 => 'text transcription',
            0x03 => 'movement/part name', 
            0x04 => 'events',             
            0x05 => 'chord',              
            0x06 => 'trivia/\'pop up\' information',
            0x07 => 'URLs to webpages',
            0x08 => 'URLs to images'
        );

        return @$lookup[$index];
    }



    public static function APICPictureTypeLookup($index, $return_array=false) {

        static $lookup = array (
            0x00 => 'Other',
            0x01 => '32x32 pixels \'file icon\' (PNG only)',
            0x02 => 'Other file icon',
            0x03 => 'Cover (front)',
            0x04 => 'Cover (back)',
            0x05 => 'Leaflet page',
            0x06 => 'Media (e.g. label side of CD)',
            0x07 => 'Lead artist/lead performer/soloist',
            0x08 => 'Artist/performer',
            0x09 => 'Conductor',
            0x0A => 'Band/Orchestra',
            0x0B => 'Composer',
            0x0C => 'Lyricist/text writer',
            0x0D => 'Recording Location',
            0x0E => 'During recording',
            0x0F => 'During performance',
            0x10 => 'Movie/video screen capture',
            0x11 => 'A bright coloured fish',
            0x12 => 'Illustration',
            0x13 => 'Band/artist logotype',
            0x14 => 'Publisher/Studio logotype'
        );

        if ($return_array) {
            return $lookup;
        }
        return @$lookup[$index];
    }



    public static function COMRReceivedAsLookup($index) {

        static $lookup = array (
            0x00 => 'Other',
            0x01 => 'Standard CD album with other songs',
            0x02 => 'Compressed audio on CD',
            0x03 => 'File over the Internet',
            0x04 => 'Stream over the Internet',
            0x05 => 'As note sheets',
            0x06 => 'As note sheets in a book with other sheets',
            0x07 => 'Music on other media',
            0x08 => 'Non-musical merchandise'
        );

        return (isset($lookup[$index]) ? $lookup[$index] : '');
    }



    public static function RVA2ChannelTypeLookup($index) {

        static $lookup = array (
            0x00 => 'Other',
            0x01 => 'Master volume',
            0x02 => 'Front right',
            0x03 => 'Front left',
            0x04 => 'Back right',
            0x05 => 'Back left',
            0x06 => 'Front centre',
            0x07 => 'Back centre',
            0x08 => 'Subwoofer'
        );

        return @$lookup[$index];
    }



    public static function FrameNameLongLookup($frame_name) {

        static $lookup = array (
            'AENC' => 'Audio encryption',
            'APIC' => 'Attached picture',
            'ASPI' => 'Audio seek point index',
            'BUF'  => 'Recommended buffer size',
            'CNT'  => 'Play counter',
            'COM'  => 'Comments',
            'COMM' => 'Comments',
            'COMR' => 'Commercial frame',
            'CRA'  => 'Audio encryption',
            'CRM'  => 'Encrypted meta frame',
            'ENCR' => 'Encryption method registration',
            'EQU'  => 'Equalisation',
            'EQU2' => 'Equalisation (2)',
            'EQUA' => 'Equalisation',
            'ETC'  => 'Event timing codes',
            'ETCO' => 'Event timing codes',
            'GEO'  => 'General encapsulated object',
            'GEOB' => 'General encapsulated object',
            'GRID' => 'Group identification registration',
            'IPL'  => 'Involved people list',
            'IPLS' => 'Involved people list',
            'LINK' => 'Linked information',
            'LNK'  => 'Linked information',
            'MCDI' => 'Music CD identifier',
            'MCI'  => 'Music CD Identifier',
            'MLL'  => 'MPEG location lookup table',
            'MLLT' => 'MPEG location lookup table',
            'OWNE' => 'Ownership frame',
            'PCNT' => 'Play counter',
            'PIC'  => 'Attached picture',
            'POP'  => 'Popularimeter',
            'POPM' => 'Popularimeter',
            'POSS' => 'Position synchronisation frame',
            'PRIV' => 'Private frame',
            'RBUF' => 'Recommended buffer size',
            'REV'  => 'Reverb',
            'RVA'  => 'Relative volume adjustment',
            'RVA2' => 'Relative volume adjustment (2)',
            'RVAD' => 'Relative volume adjustment',
            'RVRB' => 'Reverb',
            'SEEK' => 'Seek frame',
            'SIGN' => 'Signature frame',
            'SLT'  => 'Synchronised lyric/text',
            'STC'  => 'Synced tempo codes',
            'SYLT' => 'Synchronised lyric/text',
            'SYTC' => 'Synchronised tempo codes',
            'TAL'  => 'Album/Movie/Show title',
            'TALB' => 'Album/Movie/Show title',
            'TBP'  => 'BPM (Beats Per Minute)',
            'TBPM' => 'BPM (beats per minute)',
            'TCM'  => 'Composer',
            'TCO'  => 'Content type',
            'TCOM' => 'Composer',
            'TCON' => 'Content type',
            'TCOP' => 'Copyright message',
            'TCR'  => 'Copyright message',
            'TDA'  => 'Date',
            'TDAT' => 'Date',
            'TDEN' => 'Encoding time',
            'TDLY' => 'Playlist delay',
            'TDOR' => 'Original release time',
            'TDRC' => 'Recording time',
            'TDRL' => 'Release time',
            'TDTG' => 'Tagging time',
            'TDY'  => 'Playlist delay',
            'TEN'  => 'Encoded by',
            'TENC' => 'Encoded by',
            'TEXT' => 'Lyricist/Text writer',
            'TFLT' => 'File type',
            'TFT'  => 'File type',
            'TIM'  => 'Time',
            'TIME' => 'Time',
            'TIPL' => 'Involved people list',
            'TIT1' => 'Content group description',
            'TIT2' => 'Title/songname/content description',
            'TIT3' => 'Subtitle/Description refinement',
            'TKE'  => 'Initial key',
            'TKEY' => 'Initial key',
            'TLA'  => 'Language(s)',
            'TLAN' => 'Language(s)',
            'TLE'  => 'Length',
            'TLEN' => 'Length',
            'TMCL' => 'Musician credits list',
            'TMED' => 'Media type',
            'TMOO' => 'Mood',
            'TMT'  => 'Media type',
            'TOA'  => 'Original artist(s)/performer(s)',
            'TOAL' => 'Original album/movie/show title',
            'TOF'  => 'Original filename',
            'TOFN' => 'Original filename',
            'TOL'  => 'Original Lyricist(s)/text writer(s)',
            'TOLY' => 'Original lyricist(s)/text writer(s)',
            'TOPE' => 'Original artist(s)/performer(s)',
            'TOR'  => 'Original release year',
            'TORY' => 'Original release year',
            'TOT'  => 'Original album/Movie/Show title',
            'TOWN' => 'File owner/licensee',
            'TP1'  => 'Lead artist(s)/Lead performer(s)/Soloist(s)/Performing group',
            'TP2'  => 'Band/Orchestra/Accompaniment',
            'TP3'  => 'Conductor/Performer refinement',
            'TP4'  => 'Interpreted, remixed, or otherwise modified by',
            'TPA'  => 'Part of a set',
            'TPB'  => 'Publisher',
            'TPE1' => 'Lead performer(s)/Soloist(s)',
            'TPE2' => 'Band/orchestra/accompaniment',
            'TPE3' => 'Conductor/performer refinement',
            'TPE4' => 'Interpreted, remixed, or otherwise modified by',
            'TPOS' => 'Part of a set',
            'TPRO' => 'Produced notice',
            'TPUB' => 'Publisher',
            'TRC'  => 'ISRC (International Standard Recording Code)',
            'TRCK' => 'Track number/Position in set',
            'TRD'  => 'Recording dates',
            'TRDA' => 'Recording dates',
            'TRK'  => 'Track number/Position in set',
            'TRSN' => 'Internet radio station name',
            'TRSO' => 'Internet radio station owner',
            'TSI'  => 'Size',
            'TSIZ' => 'Size',
            'TSOA' => 'Album sort order',
            'TSOP' => 'Performer sort order',
            'TSOT' => 'Title sort order',
            'TSRC' => 'ISRC (international standard recording code)',
            'TSS'  => 'Software/hardware and settings used for encoding',
            'TSSE' => 'Software/Hardware and settings used for encoding',
            'TSST' => 'Set subtitle',
            'TT1'  => 'Content group description',
            'TT2'  => 'Title/Songname/Content description',
            'TT3'  => 'Subtitle/Description refinement',
            'TXT'  => 'Lyricist/text writer',
            'TXX'  => 'User defined text information frame',
            'TXXX' => 'User defined text information frame',
            'TYE'  => 'Year',
            'TYER' => 'Year',
            'UFI'  => 'Unique file identifier',
            'UFID' => 'Unique file identifier',
            'ULT'  => 'Unsychronised lyric/text transcription',
            'USER' => 'Terms of use',
            'USLT' => 'Unsynchronised lyric/text transcription',
            'WAF'  => 'Official audio file webpage',
            'WAR'  => 'Official artist/performer webpage',
            'WAS'  => 'Official audio source webpage',
            'WCM'  => 'Commercial information',
            'WCOM' => 'Commercial information',
            'WCOP' => 'Copyright/Legal information',
            'WCP'  => 'Copyright/Legal information',
            'WOAF' => 'Official audio file webpage',
            'WOAR' => 'Official artist/performer webpage',
            'WOAS' => 'Official audio source webpage',
            'WORS' => 'Official Internet radio station homepage',
            'WPAY' => 'Payment',
            'WPB'  => 'Publishers official webpage',
            'WPUB' => 'Publishers official webpage',
            'WXX'  => 'User defined URL link frame',
            'WXXX' => 'User defined URL link frame',
            'TFEA' => 'Featured Artist',
            'TSTU' => 'Recording Studio',
            'rgad' => 'Replay Gain Adjustment'
        );

        return @$lookup[$frame_name];

        
        
        
    }


    public static function FrameNameShortLookup($frame_name) {

        static $lookup = array (
            'COM'  => 'comment',
            'COMM' => 'comment',
            'TAL'  => 'album',
            'TALB' => 'album',
            'TBP'  => 'bpm',
            'TBPM' => 'bpm',
            'TCM'  => 'composer',
            'TCO'  => 'genre',
            'TCOM' => 'composer',
            'TCON' => 'genre',
            'TCOP' => 'copyright',
            'TCR'  => 'copyright',
            'TEN'  => 'encoded_by',
            'TENC' => 'encoded_by',
            'TEXT' => 'lyricist',
            'TIT1' => 'description',
            'TIT2' => 'title',
            'TIT3' => 'subtitle',
            'TLA'  => 'language',
            'TLAN' => 'language',
            'TLE'  => 'length',
            'TLEN' => 'length',
            'TMOO' => 'mood',
            'TOA'  => 'original_artist',
            'TOAL' => 'original_album',
            'TOF'  => 'original_filename',
            'TOFN' => 'original_filename',
            'TOL'  => 'original_lyricist',
            'TOLY' => 'original_lyricist',
            'TOPE' => 'original_artist',
            'TOT'  => 'original_album',
            'TP1'  => 'artist',
            'TP2'  => 'band',
            'TP3'  => 'conductor',
            'TP4'  => 'remixer',
            'TPB'  => 'publisher',
            'TPE1' => 'artist',
            'TPE2' => 'band',
            'TPE3' => 'conductor',
            'TPE4' => 'remixer',
            'TPUB' => 'publisher',
            'TRC'  => 'isrc',
            'TRCK' => 'track',
            'TRK'  => 'track',
            'TSI'  => 'size',
            'TSIZ' => 'size',
            'TSRC' => 'isrc',
            'TSS'  => 'encoder_settings',
            'TSSE' => 'encoder_settings',
            'TSST' => 'subtitle',
            'TT1'  => 'description',
            'TT2'  => 'title',
            'TT3'  => 'subtitle',
            'TXT'  => 'lyricist',
            'TXX'  => 'text',
            'TXXX' => 'text',
            'TYE'  => 'year',
            'TYER' => 'year',
            'UFI'  => 'unique_file_identifier',
            'UFID' => 'unique_file_identifier',
            'ULT'  => 'unsychronised_lyric',
            'USER' => 'terms_of_use',
            'USLT' => 'unsynchronised lyric',
            'WAF'  => 'url_file',
            'WAR'  => 'url_artist',
            'WAS'  => 'url_source',
            'WCOP' => 'copyright',
            'WCP'  => 'copyright',
            'WOAF' => 'url_file',
            'WOAR' => 'url_artist',
            'WOAS' => 'url_source',
            'WORS' => 'url_station',
            'WPB'  => 'url_publisher',
            'WPUB' => 'url_publisher',
            'WXX'  => 'url_user',
            'WXXX' => 'url_user',
            'TFEA' => 'featured_artist',
            'TSTU' => 'studio'
        );

        return @$lookup[$frame_name];
    }



    public static function TextEncodingTerminatorLookup($encoding) {

        
        
        
        
        
        

        static $lookup = array (
            0   => "\x00",
            1   => "\x00\x00",
            2   => "\x00\x00",
            3   => "\x00",
            255 => "\x00\x00"
        );

        return @$lookup[$encoding];
    }



    public static function IsValidID3v2FrameName($frame_name, $id3v2_major_version) {

        switch ($id3v2_major_version) {
            case 2:
                return preg_match('/[A-Z][A-Z0-9]{2}/', $frame_name);

            case 3:
            case 4:
                return preg_match('/[A-Z][A-Z0-9]{3}/', $frame_name);
        }
        return false;
    }



    public static function IsValidDateStampString($date_stamp) {

        if (strlen($date_stamp) != 8) {
            return false;
        }
        if ((int)$date_stamp) {
            return false;
        }

        $year  = substr($date_stamp, 0, 4);
        $month = substr($date_stamp, 4, 2);
        $day   = substr($date_stamp, 6, 2);
        if (!$year  ||  !$month  ||  !$day  ||  $month > 12  ||  $day > 31 ) {
            return false;
        }
        if (($day > 30) && (($month == 4) || ($month == 6) || ($month == 9) || ($month == 11))) {
            return false;
        }
        if (($day > 29) && ($month == 2)) {
            return false;
        }
        return true;
    }



    public static function array_merge_noclobber($array1, $array2) {
        if (!is_array($array1) || !is_array($array2)) {
            return false;
        }
        $newarray = $array1;
        foreach ($array2 as $key => $val) {
            if (is_array($val) && isset($newarray[$key]) && is_array($newarray[$key])) {
                $newarray[$key] = getid3_id3v2::array_merge_noclobber($newarray[$key], $val);
            } elseif (!isset($newarray[$key])) {
                $newarray[$key] = $val;
            }
        }
        return $newarray;
    }


}













class getid3_id3v1 extends getid3_handler
{

    public function Analyze() {

        $getid3 = $this->getid3;

        fseek($getid3->fp, -256, SEEK_END);
        $pre_id3v1 = fread($getid3->fp, 128);
        $id3v1_tag = fread($getid3->fp, 128);

        if (substr($id3v1_tag, 0, 3) == 'TAG') {

            $getid3->info['avdataend'] -= 128;

            
            $getid3->info['id3v1'] = array ();
            $info_id3v1 = &$getid3->info['id3v1'];

            $info_id3v1['title']   = getid3_id3v1::cutfield(substr($id3v1_tag,  3, 30));
            $info_id3v1['artist']  = getid3_id3v1::cutfield(substr($id3v1_tag, 33, 30));
            $info_id3v1['album']   = getid3_id3v1::cutfield(substr($id3v1_tag, 63, 30));
            $info_id3v1['year']    = getid3_id3v1::cutfield(substr($id3v1_tag, 93,  4));
            $info_id3v1['comment'] = substr($id3v1_tag,  97, 30);  
            $info_id3v1['genreid'] = ord(substr($id3v1_tag, 127, 1));

            
            if (($id3v1_tag{125} === "\x00") && ($id3v1_tag{126} !== "\x00")) {
                $info_id3v1['track']   = ord(substr($info_id3v1['comment'], 29,  1));
                $info_id3v1['comment'] =     substr($info_id3v1['comment'],  0, 28);
            }
            $info_id3v1['comment'] = getid3_id3v1::cutfield($info_id3v1['comment']);

            $info_id3v1['genre'] = getid3_id3v1::LookupGenreName($info_id3v1['genreid']);
            if (!empty($info_id3v1['genre'])) {
                unset($info_id3v1['genreid']);
            }
            if (empty($info_id3v1['genre']) || (@$info_id3v1['genre'] == 'Unknown')) {
                unset($info_id3v1['genre']);
            }

            foreach ($info_id3v1 as $key => $value) {
                $key != 'comments' and $info_id3v1['comments'][$key][0] = $value;
            }

            $info_id3v1['tag_offset_end']   = filesize($getid3->filename);
            $info_id3v1['tag_offset_start'] = $info_id3v1['tag_offset_end'] - 128;
        }

        if (substr($pre_id3v1, 0, 3) == 'TAG') {
            
            
            

            
            if (substr($pre_id3v1, 96, 8) == 'APETAGEX') {
                
            } elseif (substr($pre_id3v1, 119, 6) == 'LYRICS') {
                
            } else {
                
                $getid3->warning('Duplicate ID3v1 tag detected - this has been known to happen with iTunes.');
                $getid3->info['avdataend'] -= 128;
            }
        }

        return true;
    }



    public static function cutfield($str) {

        return trim(substr($str, 0, strcspn($str, "\x00")));
    }



    public static function ArrayOfGenres($allow_SCMPX_extended=false) {

        static $lookup = array (
            0    => 'Blues',
            1    => 'Classic Rock',
            2    => 'Country',
            3    => 'Dance',
            4    => 'Disco',
            5    => 'Funk',
            6    => 'Grunge',
            7    => 'Hip-Hop',
            8    => 'Jazz',
            9    => 'Metal',
            10   => 'New Age',
            11   => 'Oldies',
            12   => 'Other',
            13   => 'Pop',
            14   => 'R&B',
            15   => 'Rap',
            16   => 'Reggae',
            17   => 'Rock',
            18   => 'Techno',
            19   => 'Industrial',
            20   => 'Alternative',
            21   => 'Ska',
            22   => 'Death Metal',
            23   => 'Pranks',
            24   => 'Soundtrack',
            25   => 'Euro-Techno',
            26   => 'Ambient',
            27   => 'Trip-Hop',
            28   => 'Vocal',
            29   => 'Jazz+Funk',
            30   => 'Fusion',
            31   => 'Trance',
            32   => 'Classical',
            33   => 'Instrumental',
            34   => 'Acid',
            35   => 'House',
            36   => 'Game',
            37   => 'Sound Clip',
            38   => 'Gospel',
            39   => 'Noise',
            40   => 'Alt. Rock',
            41   => 'Bass',
            42   => 'Soul',
            43   => 'Punk',
            44   => 'Space',
            45   => 'Meditative',
            46   => 'Instrumental Pop',
            47   => 'Instrumental Rock',
            48   => 'Ethnic',
            49   => 'Gothic',
            50   => 'Darkwave',
            51   => 'Techno-Industrial',
            52   => 'Electronic',
            53   => 'Pop-Folk',
            54   => 'Eurodance',
            55   => 'Dream',
            56   => 'Southern Rock',
            57   => 'Comedy',
            58   => 'Cult',
            59   => 'Gangsta Rap',
            60   => 'Top 40',
            61   => 'Christian Rap',
            62   => 'Pop/Funk',
            63   => 'Jungle',
            64   => 'Native American',
            65   => 'Cabaret',
            66   => 'New Wave',
            67   => 'Psychedelic',
            68   => 'Rave',
            69   => 'Showtunes',
            70   => 'Trailer',
            71   => 'Lo-Fi',
            72   => 'Tribal',
            73   => 'Acid Punk',
            74   => 'Acid Jazz',
            75   => 'Polka',
            76   => 'Retro',
            77   => 'Musical',
            78   => 'Rock & Roll',
            79   => 'Hard Rock',
            80   => 'Folk',
            81   => 'Folk/Rock',
            82   => 'National Folk',
            83   => 'Swing',
            84   => 'Fast-Fusion',
            85   => 'Bebob',
            86   => 'Latin',
            87   => 'Revival',
            88   => 'Celtic',
            89   => 'Bluegrass',
            90   => 'Avantgarde',
            91   => 'Gothic Rock',
            92   => 'Progressive Rock',
            93   => 'Psychedelic Rock',
            94   => 'Symphonic Rock',
            95   => 'Slow Rock',
            96   => 'Big Band',
            97   => 'Chorus',
            98   => 'Easy Listening',
            99   => 'Acoustic',
            100  => 'Humour',
            101  => 'Speech',
            102  => 'Chanson',
            103  => 'Opera',
            104  => 'Chamber Music',
            105  => 'Sonata',
            106  => 'Symphony',
            107  => 'Booty Bass',
            108  => 'Primus',
            109  => 'Porn Groove',
            110  => 'Satire',
            111  => 'Slow Jam',
            112  => 'Club',
            113  => 'Tango',
            114  => 'Samba',
            115  => 'Folklore',
            116  => 'Ballad',
            117  => 'Power Ballad',
            118  => 'Rhythmic Soul',
            119  => 'Freestyle',
            120  => 'Duet',
            121  => 'Punk Rock',
            122  => 'Drum Solo',
            123  => 'A Cappella',
            124  => 'Euro-House',
            125  => 'Dance Hall',
            126  => 'Goa',
            127  => 'Drum & Bass',
            128  => 'Club-House',
            129  => 'Hardcore',
            130  => 'Terror',
            131  => 'Indie',
            132  => 'BritPop',
            133  => 'Negerpunk',
            134  => 'Polsk Punk',
            135  => 'Beat',
            136  => 'Christian Gangsta Rap',
            137  => 'Heavy Metal',
            138  => 'Black Metal',
            139  => 'Crossover',
            140  => 'Contemporary Christian',
            141  => 'Christian Rock',
            142  => 'Merengue',
            143  => 'Salsa',
            144  => 'Trash Metal',
            145  => 'Anime',
            146  => 'JPop',
            147  => 'Synthpop',

            255  => 'Unknown',

            'CR' => 'Cover',
            'RX' => 'Remix'
        );

        static $lookupSCMPX = array ();
        if ($allow_SCMPX_extended && empty($lookupSCMPX)) {
            $lookupSCMPX = $lookup;
            
            
            
            $lookupSCMPX[240] = 'Sacred';
            $lookupSCMPX[241] = 'Northern Europe';
            $lookupSCMPX[242] = 'Irish & Scottish';
            $lookupSCMPX[243] = 'Scotland';
            $lookupSCMPX[244] = 'Ethnic Europe';
            $lookupSCMPX[245] = 'Enka';
            $lookupSCMPX[246] = 'Children\'s Song';
            $lookupSCMPX[247] = 'Japanese Sky';
            $lookupSCMPX[248] = 'Japanese Heavy Rock';
            $lookupSCMPX[249] = 'Japanese Doom Rock';
            $lookupSCMPX[250] = 'Japanese J-POP';
            $lookupSCMPX[251] = 'Japanese Seiyu';
            $lookupSCMPX[252] = 'Japanese Ambient Techno';
            $lookupSCMPX[253] = 'Japanese Moemoe';
            $lookupSCMPX[254] = 'Japanese Tokusatsu';
            
        }

        return ($allow_SCMPX_extended ? $lookupSCMPX : $lookup);
    }



    public static function LookupGenreName($genre_id, $allow_SCMPX_extended=true) {

        switch ($genre_id) {
            case 'RX':
            case 'CR':
                break;
            default:
                $genre_id = intval($genre_id); 
                break;
        }
        $lookup = getid3_id3v1::ArrayOfGenres($allow_SCMPX_extended);
        return (isset($lookup[$genre_id]) ? $lookup[$genre_id] : false);
    }


    public static function LookupGenreID($genre, $allow_SCMPX_extended=false) {

        $lookup = getid3_id3v1::ArrayOfGenres($allow_SCMPX_extended);
        $lower_case_no_space_search_term = strtolower(str_replace(' ', '', $genre));
        foreach ($lookup as $key => $value) {
            foreach ($lookup as $key => $value) {
                if (strtolower(str_replace(' ', '', $value)) == $lower_case_no_space_search_term) {
                    return $key;
                }
            }
            return false;
        }
        return (isset($lookup[$genre_id]) ? $lookup[$genre_id] : false);
    }

}











class getid3_apetag extends getid3_handler
{
    /*
    ID3v1_TAG_SIZE     = 128;
    APETAG_HEADER_SIZE = 32;
    LYRICS3_TAG_SIZE   = 10;
    */

    public $option_override_end_offset = 0;



    public function Analyze() {

        $getid3 = $this->getid3;

        if ($this->option_override_end_offset == 0) {

            fseek($getid3->fp, 0 - 170, SEEK_END);                                                              
            $apetag_footer_id3v1 = fread($getid3->fp, 170);                                                     

            
            if (substr($apetag_footer_id3v1, strlen($apetag_footer_id3v1) - 160, 8) == 'APETAGEX') {            
                $getid3->info['ape']['tag_offset_end'] = filesize($getid3->filename) - 128;                     
            }

            
            elseif (substr($apetag_footer_id3v1, strlen($apetag_footer_id3v1) - 32, 8) == 'APETAGEX') {         
                $getid3->info['ape']['tag_offset_end'] = filesize($getid3->filename);
            }

        }
        else {

            fseek($getid3->fp, $this->option_override_end_offset - 32, SEEK_SET);                               
            if (fread($getid3->fp, 8) == 'APETAGEX') {
                $getid3->info['ape']['tag_offset_end'] = $this->option_override_end_offset;
            }

        }

        
        if (!@$getid3->info['ape']['tag_offset_end']) {
            return false;
        }

        
        $info_ape = &$getid3->info['ape'];

        
        fseek($getid3->fp, $info_ape['tag_offset_end'] - 32, SEEK_SET);                                         
        $apetag_footer_data = fread($getid3->fp, 32);
        if (!($this->ParseAPEHeaderFooter($apetag_footer_data, $info_ape['footer']))) {
            throw new getid3_exception('Error parsing APE footer at offset '.$info_ape['tag_offset_end']);
        }

        if (isset($info_ape['footer']['flags']['header']) && $info_ape['footer']['flags']['header']) {
            fseek($getid3->fp, $info_ape['tag_offset_end'] - $info_ape['footer']['raw']['tagsize'] - 32, SEEK_SET);
            $info_ape['tag_offset_start'] = ftell($getid3->fp);
            $apetag_data = fread($getid3->fp, $info_ape['footer']['raw']['tagsize'] + 32);
        }
        else {
            $info_ape['tag_offset_start'] = $info_ape['tag_offset_end'] - $info_ape['footer']['raw']['tagsize'];
            fseek($getid3->fp, $info_ape['tag_offset_start'], SEEK_SET);
            $apetag_data = fread($getid3->fp, $info_ape['footer']['raw']['tagsize']);
        }
        $getid3->info['avdataend'] = $info_ape['tag_offset_start'];

        if (isset($getid3->info['id3v1']['tag_offset_start']) && ($getid3->info['id3v1']['tag_offset_start'] < $info_ape['tag_offset_end'])) {
            $getid3->warning('ID3v1 tag information ignored since it appears to be a false synch in APEtag data');
            unset($getid3->info['id3v1']);
        }

        $offset = 0;
        if (isset($info_ape['footer']['flags']['header']) && $info_ape['footer']['flags']['header']) {
            if (!$this->ParseAPEHeaderFooter(substr($apetag_data, 0, 32), $info_ape['header'])) {
                throw new getid3_exception('Error parsing APE header at offset '.$info_ape['tag_offset_start']);
            }
            $offset = 32;
        }

        
        $getid3->info['replay_gain'] = array ();
        $info_replaygain = &$getid3->info['replay_gain'];

        for ($i = 0; $i < $info_ape['footer']['raw']['tag_items']; $i++) {
            $value_size = getid3_lib::LittleEndian2Int(substr($apetag_data, $offset,     4));
            $item_flags = getid3_lib::LittleEndian2Int(substr($apetag_data, $offset + 4, 4));
            $offset += 8;

            if (strstr(substr($apetag_data, $offset), "\x00") === false) {
                throw new getid3_exception('Cannot find null-byte (0x00) seperator between ItemKey #'.$i.' and value. ItemKey starts ' . $offset . ' bytes into the APE tag, at file offset '.($info_ape['tag_offset_start'] + $offset));
            }

            $item_key_length = strpos($apetag_data, "\x00", $offset) - $offset;
            $item_key        = strtolower(substr($apetag_data, $offset, $item_key_length));

            
            $info_ape['items'][$item_key] = array ();
            $info_ape_items_current = &$info_ape['items'][$item_key];

            $offset += $item_key_length + 1; 
            $info_ape_items_current['data'] = substr($apetag_data, $offset, $value_size);
            $offset += $value_size;


            $info_ape_items_current['flags'] = $this->ParseAPEtagFlags($item_flags);

            switch ($info_ape_items_current['flags']['item_contents_raw']) {
                case 0: 
                case 3: 
                    $info_ape_items_current['data'] = explode("\x00", trim($info_ape_items_current['data']));
                    break;

                default: 
                    break;
            }

            switch (strtolower($item_key)) {
                case 'replaygain_track_gain':
                    $info_replaygain['track']['adjustment'] = (float)str_replace(',', '.', $info_ape_items_current['data'][0]); 
                    $info_replaygain['track']['originator'] = 'unspecified';
                    break;

                case 'replaygain_track_peak':
                    $info_replaygain['track']['peak']       = (float)str_replace(',', '.', $info_ape_items_current['data'][0]); 
                    $info_replaygain['track']['originator'] = 'unspecified';
                    if ($info_replaygain['track']['peak'] <= 0) {
                        $getid3->warning('ReplayGain Track peak from APEtag appears invalid: '.$info_replaygain['track']['peak'].' (original value = "'.$info_ape_items_current['data'][0].'")');
                    }
                    break;

                case 'replaygain_album_gain':
                    $info_replaygain['album']['adjustment'] = (float)str_replace(',', '.', $info_ape_items_current['data'][0]); 
                    $info_replaygain['album']['originator'] = 'unspecified';
                    break;

                case 'replaygain_album_peak':
                    $info_replaygain['album']['peak']       = (float)str_replace(',', '.', $info_ape_items_current['data'][0]); 
                    $info_replaygain['album']['originator'] = 'unspecified';
                    if ($info_replaygain['album']['peak'] <= 0) {
                        $getid3->warning('ReplayGain Album peak from APEtag appears invalid: '.$info_replaygain['album']['peak'].' (original value = "'.$info_ape_items_current['data'][0].'")');
                    }
                    break;

                case 'mp3gain_undo':
                    list($mp3gain_undo_left, $mp3gain_undo_right, $mp3gain_undo_wrap) = explode(',', $info_ape_items_current['data'][0]);
                    $info_replaygain['mp3gain']['undo_left']  = intval($mp3gain_undo_left);
                    $info_replaygain['mp3gain']['undo_right'] = intval($mp3gain_undo_right);
                    $info_replaygain['mp3gain']['undo_wrap']  = (($mp3gain_undo_wrap == 'Y') ? true : false);
                    break;

                case 'mp3gain_minmax':
                    list($mp3gain_globalgain_min, $mp3gain_globalgain_max) = explode(',', $info_ape_items_current['data'][0]);
                    $info_replaygain['mp3gain']['globalgain_track_min'] = intval($mp3gain_globalgain_min);
                    $info_replaygain['mp3gain']['globalgain_track_max'] = intval($mp3gain_globalgain_max);
                    break;

                case 'mp3gain_album_minmax':
                    list($mp3gain_globalgain_album_min, $mp3gain_globalgain_album_max) = explode(',', $info_ape_items_current['data'][0]);
                    $info_replaygain['mp3gain']['globalgain_album_min'] = intval($mp3gain_globalgain_album_min);
                    $info_replaygain['mp3gain']['globalgain_album_max'] = intval($mp3gain_globalgain_album_max);
                    break;

                case 'tracknumber':
                    foreach ($info_ape_items_current['data'] as $comment) {
                        $info_ape['comments']['track'][] = $comment;
                    }
                    break;

                default:
                	if (is_array($info_ape_items_current['data'])) {
	                    foreach ($info_ape_items_current['data'] as $comment) {
	                        $info_ape['comments'][strtolower($item_key)][] = $comment;
	                    }
	                }
                    break;
            }

        }
        if (empty($info_replaygain)) {
            unset($getid3->info['replay_gain']);
        }

        return true;
    }



    protected function ParseAPEheaderFooter($data, &$target) {

        

        if (substr($data, 0, 8) != 'APETAGEX') {
            return false;
        }

        
        $target['raw'] = array ();
        $target_raw = &$target['raw'];

        $target_raw['footer_tag']   = 'APETAGEX';

        getid3_lib::ReadSequence("LittleEndian2Int", $target_raw, $data, 8,
            array (
                'version'      => 4,
                'tagsize'      => 4,
                'tag_items'    => 4,
                'global_flags' => 4
            )
        );
        $target_raw['reserved'] = substr($data, 24, 8);

        $target['tag_version'] = $target_raw['version'] / 1000;
        if ($target['tag_version'] >= 2) {

            $target['flags'] = $this->ParseAPEtagFlags($target_raw['global_flags']);
        }

        return true;
    }



    protected function ParseAPEtagFlags($raw_flag_int) {

        
        
        

        $target['header']            = (bool) ($raw_flag_int & 0x80000000);
        $target['footer']            = (bool) ($raw_flag_int & 0x40000000);
        $target['this_is_header']    = (bool) ($raw_flag_int & 0x20000000);
        $target['item_contents_raw'] =        ($raw_flag_int & 0x00000006) >> 1;
        $target['read_only']         = (bool) ($raw_flag_int & 0x00000001);

        $target['item_contents']     = getid3_apetag::APEcontentTypeFlagLookup($target['item_contents_raw']);

        return $target;
    }



    public static function APEcontentTypeFlagLookup($content_type_id) {

        static $lookup = array (
            0 => 'utf-8',
            1 => 'binary',
            2 => 'external',
            3 => 'reserved'
        );
        return (isset($lookup[$content_type_id]) ? $lookup[$content_type_id] : 'invalid');
    }



    public static function APEtagItemIsUTF8Lookup($item_key) {

        static $lookup = array (
            'title',
            'subtitle',
            'artist',
            'album',
            'debut album',
            'publisher',
            'conductor',
            'track',
            'composer',
            'comment',
            'copyright',
            'publicationright',
            'file',
            'year',
            'record date',
            'record location',
            'genre',
            'media',
            'related',
            'isrc',
            'abstract',
            'language',
            'bibliography'
        );
        return in_array(strtolower($item_key), $lookup);
    }

}












class getid3_lyrics3 extends getid3_handler
{

    public function Analyze() {

        $getid3 = $this->getid3;

        fseek($getid3->fp, (0 - 128 - 9 - 6), SEEK_END);  
        $lyrics3_id3v1 = fread($getid3->fp, 128 + 9 + 6);
        $lyrics3_lsz   = substr($lyrics3_id3v1,  0,   6); 
        $lyrics3_end   = substr($lyrics3_id3v1,  6,   9); 
        $id3v1_tag     = substr($lyrics3_id3v1, 15, 128); 

        
        if ($lyrics3_end == 'LYRICSEND') {

            $lyrics3_size    = 5100;
            $lyrics3_offset  = filesize($getid3->filename) - 128 - $lyrics3_size;
            $lyrics3_version = 1;
        }

        
        elseif ($lyrics3_end == 'LYRICS200') {

            
            $lyrics3_size    = $lyrics3_lsz + 6 + strlen('LYRICS200');
            $lyrics3_offset  = filesize($getid3->filename) - 128 - $lyrics3_size;
            $lyrics3_version = 2;
        }

        
        elseif (substr(strrev($lyrics3_id3v1), 0, 9) == 'DNESCIRYL') {            

            $lyrics3_size    = 5100;
            $lyrics3_offset  = filesize($getid3->filename) - $lyrics3_size;
            $lyrics3_version = 1;
            $lyrics3_offset  = filesize($getid3->filename) - $lyrics3_size;
        }

        
        elseif (substr(strrev($lyrics3_id3v1), 0, 9) == '002SCIRYL') {             

            $lyrics3_size    = strrev(substr(strrev($lyrics3_id3v1), 9, 6)) + 15;   
            $lyrics3_offset  = filesize($getid3->filename) - $lyrics3_size;
            $lyrics3_version = 2;
        }

        elseif (isset($getid3->info['ape']['tag_offset_start']) && ($getid3->info['ape']['tag_offset_start'] > 15)) {

            fseek($getid3->fp, $getid3->info['ape']['tag_offset_start'] - 15, SEEK_SET);
            $lyrics3_lsz = fread($getid3->fp, 6);
            $lyrics3_end = fread($getid3->fp, 9);


            
            if ($lyrics3_end == 'LYRICSEND') {

                $lyrics3_size    = 5100;
                $lyrics3_offset  = $getid3->info['ape']['tag_offset_start'] - $lyrics3_size;
                $getid3->info['avdataend'] = $lyrics3_offset;
                $lyrics3_version = 1;
                $getid3->warning('APE tag located after Lyrics3, will probably break Lyrics3 compatability');
            }


            
            elseif ($lyrics3_end == 'LYRICS200') {

                $lyrics3_size    = $lyrics3_lsz + 15; 
                $lyrics3_offset  = $getid3->info['ape']['tag_offset_start'] - $lyrics3_size;
                $lyrics3_version = 2;
                $getid3->warning('APE tag located after Lyrics3, will probably break Lyrics3 compatability');

            }
        }


        


        if (isset($lyrics3_offset)) {

            $getid3->info['avdataend'] = $lyrics3_offset;

            if ($lyrics3_size <= 0) {
                return false;
            }

            fseek($getid3->fp, $lyrics3_offset, SEEK_SET);
            $raw_data = fread($getid3->fp, $lyrics3_size);

            if (substr($raw_data, 0, 11) != 'LYRICSBEGIN') {
                if (strpos($raw_data, 'LYRICSBEGIN') !== false) {

                    $getid3->warning('"LYRICSBEGIN" expected at '.$lyrics3_offset.' but actually found at '.($lyrics3_offset + strpos($raw_data, 'LYRICSBEGIN')).' - this is invalid for Lyrics3 v'.$lyrics3_version);
                    $getid3->info['avdataend'] = $lyrics3_offset + strpos($raw_data, 'LYRICSBEGIN');
                    $parsed_lyrics3['tag_offset_start'] = $getid3->info['avdataend'];
                    $raw_data = substr($raw_data, strpos($raw_data, 'LYRICSBEGIN'));
                    $lyrics3_size = strlen($raw_data);
                }
                else {
                    throw new getid3_exception('"LYRICSBEGIN" expected at '.$lyrics3_offset.' but found "'.substr($raw_data, 0, 11).'" instead.');
                }

            }

            $parsed_lyrics3['raw']['lyrics3version'] = $lyrics3_version;
            $parsed_lyrics3['raw']['lyrics3tagsize'] = $lyrics3_size;
            $parsed_lyrics3['tag_offset_start']      = $lyrics3_offset;
            $parsed_lyrics3['tag_offset_end']        = $lyrics3_offset + $lyrics3_size;

            switch ($lyrics3_version) {

                case 1:
                    if (substr($raw_data, strlen($raw_data) - 9, 9) == 'LYRICSEND') {
                        $parsed_lyrics3['raw']['LYR'] = trim(substr($raw_data, 11, strlen($raw_data) - 11 - 9));
                        getid3_lyrics3::Lyrics3LyricsTimestampParse($parsed_lyrics3);
                    }
                    else {
                        throw new getid3_exception('"LYRICSEND" expected at '.(ftell($getid3->fp) - 11 + $lyrics3_size - 9).' but found "'.substr($raw_data, strlen($raw_data) - 9, 9).'" instead.');
                    }
                    break;

                case 2:
                    if (substr($raw_data, strlen($raw_data) - 9, 9) == 'LYRICS200') {
                        $parsed_lyrics3['raw']['unparsed'] = substr($raw_data, 11, strlen($raw_data) - 11 - 9 - 6); 
                        $raw_data = $parsed_lyrics3['raw']['unparsed'];
                        while (strlen($raw_data) > 0) {
                            $fieldname = substr($raw_data, 0, 3);
                            $fieldsize = (int)substr($raw_data, 3, 5);
                            $parsed_lyrics3['raw'][$fieldname] = substr($raw_data, 8, $fieldsize);
                            $raw_data  = substr($raw_data, 3 + 5 + $fieldsize);
                        }

                        if (isset($parsed_lyrics3['raw']['IND'])) {
                            $i = 0;
                            foreach (array ('lyrics', 'timestamps', 'inhibitrandom') as $flagname) {
                                if (strlen($parsed_lyrics3['raw']['IND']) > ++$i) {
                                    $parsed_lyrics3['flags'][$flagname] = getid3_lyrics3::IntString2Bool(substr($parsed_lyrics3['raw']['IND'], $i, 1));
                                }
                            }
                        }

                        foreach (array ('ETT'=>'title', 'EAR'=>'artist', 'EAL'=>'album', 'INF'=>'comment', 'AUT'=>'author') as $key => $value) {
                            if (isset($parsed_lyrics3['raw'][$key])) {
                                $parsed_lyrics3['comments'][$value][] = trim($parsed_lyrics3['raw'][$key]);
                            }
                        }

                        if (isset($parsed_lyrics3['raw']['IMG'])) {
                            foreach (explode("\r\n", $parsed_lyrics3['raw']['IMG']) as $key => $image_string) {
                                if (strpos($image_string, '||') !== false) {
                                    $imagearray = explode('||', $image_string);
                                    $parsed_lyrics3['images'][$key]['filename']    = @$imagearray[0];
                                    $parsed_lyrics3['images'][$key]['description'] = @$imagearray[1];
                                    $parsed_lyrics3['images'][$key]['timestamp']   = getid3_lyrics3::Lyrics3Timestamp2Seconds(@$imagearray[2]);
                                }
                            }
                        }

                        if (isset($parsed_lyrics3['raw']['LYR'])) {
                            getid3_lyrics3::Lyrics3LyricsTimestampParse($parsed_lyrics3);
                        }
                    }
                      else {
                        throw new getid3_exception('"LYRICS200" expected at '.(ftell($getid3->fp) - 11 + $lyrics3_size - 9).' but found "'.substr($raw_data, strlen($raw_data) - 9, 9).'" instead.');
                    }
                    break;

                default:
                    throw new getid3_exception('Cannot process Lyrics3 version '.$lyrics3_version.' (only v1 and v2)');
            }

            if (isset($getid3->info['id3v1']['tag_offset_start']) && ($getid3->info['id3v1']['tag_offset_start'] < $parsed_lyrics3['tag_offset_end'])) {
                $getid3->warning('ID3v1 tag information ignored since it appears to be a false synch in Lyrics3 tag data');
                unset($getid3->info['id3v1']);
            }

            $getid3->info['lyrics3'] = $parsed_lyrics3;


            
            if (!@$getid3->info['ape'] && $getid3->option_tag_apetag && class_exists('getid3_apetag')) {
                $apetag = new getid3_apetag($getid3);
                $apetag->option_override_end_offset = $getid3->info['lyrics3']['tag_offset_start'];
                $apetag->Analyze();
            }
        }

        return true;
    }




    public static function Lyrics3Timestamp2Seconds($rawtimestamp) {
        if (preg_match('#^\\[([0-9]{2}):([0-9]{2})\\]$#', $rawtimestamp, $regs)) {
            return (int)(($regs[1] * 60) + $regs[2]);
        }
        return false;
    }



    public static function Lyrics3LyricsTimestampParse(&$lyrics3_data) {

        $lyrics_array = explode("\r\n", $lyrics3_data['raw']['LYR']);
        foreach ($lyrics_array as $key => $lyric_line) {

            while (preg_match('#^(\\[[0-9]{2}:[0-9]{2}\\])#', $lyric_line, $regs)) {
                $this_line_timestamps[] = getid3_lyrics3::Lyrics3Timestamp2Seconds($regs[0]);
                $lyric_line = str_replace($regs[0], '', $lyric_line);
            }
            $no_timestamp_lyrics_array[$key] = $lyric_line;
            if (@is_array($this_line_timestamps)) {
                sort($this_line_timestamps);
                foreach ($this_line_timestamps as $timestampkey => $timestamp) {
                    if (isset($lyrics3_data['synchedlyrics'][$timestamp])) {
                        
                        
                        $lyrics3_data['synchedlyrics'][$timestamp] .= "\r\n".$lyric_line;
                    } else {
                        $lyrics3_data['synchedlyrics'][$timestamp] = $lyric_line;
                    }
                }
            }
            unset($this_line_timestamps);
            $regs = array ();
        }
        $lyrics3_data['unsynchedlyrics'] = implode("\r\n", $no_timestamp_lyrics_array);
        if (isset($lyrics3_data['synchedlyrics']) && is_array($lyrics3_data['synchedlyrics'])) {
            ksort($lyrics3_data['synchedlyrics']);
        }
        return true;
    }



    public static function IntString2Bool($char) {

        return $char == '1' ? true : ($char == '0' ? false : null);
    }
}














class getid3_mp3 extends getid3_handler
{
    
    
    
    const VALID_CHECK_FRAMES = 35;


    public function Analyze() {

        $this->getAllMPEGInfo($this->getid3->fp, $this->getid3->info);

        return true;
    }


    public function AnalyzeMPEGaudioInfo() {

        $this->getOnlyMPEGaudioInfo($this->getid3->fp, $this->getid3->info, $this->getid3->info['avdataoffset'], false);
    }


    public function getAllMPEGInfo(&$fd, &$info) {

        $this->getOnlyMPEGaudioInfo($fd, $info, 0 + $info['avdataoffset']);

        if (isset($info['mpeg']['audio']['bitrate_mode'])) {
            $info['audio']['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
        }

        if (((isset($info['id3v2']['headerlength']) && ($info['avdataoffset'] > $info['id3v2']['headerlength'])) || (!isset($info['id3v2']) && ($info['avdataoffset'] > 0)))) {

            $synch_offset_warning = 'Unknown data before synch ';
            if (isset($info['id3v2']['headerlength'])) {
                $synch_offset_warning .= '(ID3v2 header ends at '.$info['id3v2']['headerlength'].', then '.($info['avdataoffset'] - $info['id3v2']['headerlength']).' bytes garbage, ';
            } else {
                $synch_offset_warning .= '(should be at beginning of file, ';
            }
            $synch_offset_warning .= 'synch detected at '.$info['avdataoffset'].')';
            if ($info['audio']['bitrate_mode'] == 'cbr') {

                if (!empty($info['id3v2']['headerlength']) && (($info['avdataoffset'] - $info['id3v2']['headerlength']) == $info['mpeg']['audio']['framelength'])) {

                    $synch_offset_warning .= '. This is a known problem with some versions of LAME (3.90-3.92) DLL in CBR mode.';
                    $info['audio']['codec'] = 'LAME';
                    $current_data_lame_version_string = 'LAME3.';

                } elseif (empty($info['id3v2']['headerlength']) && ($info['avdataoffset'] == $info['mpeg']['audio']['framelength'])) {

                    $synch_offset_warning .= '. This is a known problem with some versions of LAME (3.90 - 3.92) DLL in CBR mode.';
                    $info['audio']['codec'] = 'LAME';
                    $current_data_lame_version_string = 'LAME3.';

                }

            }
            $this->getid3->warning($synch_offset_warning);

        }

        if (isset($info['mpeg']['audio']['LAME'])) {
            $info['audio']['codec'] = 'LAME';
            if (!empty($info['mpeg']['audio']['LAME']['long_version'])) {
                $info['audio']['encoder'] = rtrim($info['mpeg']['audio']['LAME']['long_version'], "\x00");
            } elseif (!empty($info['mpeg']['audio']['LAME']['short_version'])) {
                $info['audio']['encoder'] = rtrim($info['mpeg']['audio']['LAME']['short_version'], "\x00");
            }
        }

        $current_data_lame_version_string = (!empty($current_data_lame_version_string) ? $current_data_lame_version_string : @$info['audio']['encoder']);
        if (!empty($current_data_lame_version_string) && (substr($current_data_lame_version_string, 0, 6) == 'LAME3.') && !preg_match('[0-9\)]', substr($current_data_lame_version_string, -1))) {
            
            
            

            
            $possibly_longer_lame_version_frame_length = 1441;

            
            $possible_lame_version_string_offset = $info['avdataend'] - $possibly_longer_lame_version_frame_length;
            fseek($fd, $possible_lame_version_string_offset);
            $possibly_longer_lame_version_data = fread($fd, $possibly_longer_lame_version_frame_length);
            switch (substr($current_data_lame_version_string, -1)) {
                case 'a':
                case 'b':
                    
                    
                    $current_data_lame_version_string = substr($current_data_lame_version_string, 0, -1);
                    break;
            }
            if (($possibly_longer_lame_version_string = strstr($possibly_longer_lame_version_data, $current_data_lame_version_string)) !== false) {
                if (substr($possibly_longer_lame_version_string, 0, strlen($current_data_lame_version_string)) == $current_data_lame_version_string) {
                    $possibly_longer_lame_version_new_string = substr($possibly_longer_lame_version_string, 0, strspn($possibly_longer_lame_version_string, 'LAME0123456789., (abcdefghijklmnopqrstuvwxyzJFSOND)')); 
                    if (strlen($possibly_longer_lame_version_new_string) > strlen(@$info['audio']['encoder'])) {
                        $info['audio']['encoder'] = $possibly_longer_lame_version_new_string;
                    }
                }
            }
        }
        if (!empty($info['audio']['encoder'])) {
            $info['audio']['encoder'] = rtrim($info['audio']['encoder'], "\x00 ");
        }

        switch (@$info['mpeg']['audio']['layer']) {
            case 1:
            case 2:
                $info['audio']['dataformat'] = 'mp'.$info['mpeg']['audio']['layer'];
                break;
        }
        if (@$info['fileformat'] == 'mp3') {
            switch ($info['audio']['dataformat']) {
                case 'mp1':
                case 'mp2':
                case 'mp3':
                    $info['fileformat'] = $info['audio']['dataformat'];
                    break;

                default:
                    $this->getid3->warning('Expecting [audio][dataformat] to be mp1/mp2/mp3 when fileformat == mp3, [audio][dataformat] actually "'.$info['audio']['dataformat'].'"');
                    break;
            }
        }

        $info['mime_type']         = 'audio/mpeg';
        $info['audio']['lossless'] = false;

        
        if (!isset($info['playtime_seconds']) && isset($info['audio']['bitrate']) && ($info['audio']['bitrate'] > 0)) {
            $info['playtime_seconds'] = ($info['avdataend'] - $info['avdataoffset']) * 8 / $info['audio']['bitrate'];
        }

        $info['audio']['encoder_options'] = getid3_mp3::GuessEncoderOptions($info);

        return true;
    }



    public static function GuessEncoderOptions(&$info) {
        
        if (!empty($info['mpeg']['audio'])) {
            $thisfile_mpeg_audio = &$info['mpeg']['audio'];
            if (!empty($thisfile_mpeg_audio['LAME'])) {
                $thisfile_mpeg_audio_lame = &$thisfile_mpeg_audio['LAME'];
            }
        }

        $encoder_options = '';
        static $named_preset_bitrates = array (16, 24, 40, 56, 112, 128, 160, 192, 256);

        if ((@$thisfile_mpeg_audio['VBR_method'] == 'Fraunhofer') && !empty($thisfile_mpeg_audio['VBR_quality'])) {

            $encoder_options = 'VBR q'.$thisfile_mpeg_audio['VBR_quality'];

        } elseif (!empty($thisfile_mpeg_audio_lame['preset_used']) && (!in_array($thisfile_mpeg_audio_lame['preset_used_id'], $named_preset_bitrates))) {

            $encoder_options = $thisfile_mpeg_audio_lame['preset_used'];

        } elseif (!empty($thisfile_mpeg_audio_lame['vbr_quality'])) {

            static $known_encoder_values = array ();
            if (empty($known_encoder_values)) {

                
                $known_encoder_values[0xFF][58][1][1][3][2][20500] = '--alt-preset insane';        
                $known_encoder_values[0xFF][58][1][1][3][2][20600] = '--alt-preset insane';        
                $known_encoder_values[0xFF][57][1][1][3][4][20500] = '--alt-preset insane';        
                $known_encoder_values['**'][78][3][2][3][2][19500] = '--alt-preset extreme';       
                $known_encoder_values['**'][78][3][2][3][2][19600] = '--alt-preset extreme';       
                $known_encoder_values['**'][78][3][1][3][2][19600] = '--alt-preset extreme';       
                $known_encoder_values['**'][78][4][2][3][2][19500] = '--alt-preset fast extreme';  
                $known_encoder_values['**'][78][4][2][3][2][19600] = '--alt-preset fast extreme';  
                $known_encoder_values['**'][78][3][2][3][4][19000] = '--alt-preset standard';      
                $known_encoder_values['**'][78][3][1][3][4][19000] = '--alt-preset standard';      
                $known_encoder_values['**'][78][4][2][3][4][19000] = '--alt-preset fast standard'; 
                $known_encoder_values['**'][78][4][1][3][4][19000] = '--alt-preset fast standard'; 
                $known_encoder_values['**'][88][4][1][3][3][19500] = '--r3mix';                    
                $known_encoder_values['**'][88][4][1][3][3][19600] = '--r3mix';                    
                $known_encoder_values['**'][67][4][1][3][4][18000] = '--r3mix';                    
                $known_encoder_values['**'][68][3][2][3][4][18000] = '--alt-preset medium';        
                $known_encoder_values['**'][68][4][2][3][4][18000] = '--alt-preset fast medium';   

                $known_encoder_values[0xFF][99][1][1][1][2][0]     = '--preset studio';            
                $known_encoder_values[0xFF][58][2][1][3][2][20600] = '--preset studio';            
                $known_encoder_values[0xFF][58][2][1][3][2][20500] = '--preset studio';            
                $known_encoder_values[0xFF][57][2][1][3][4][20500] = '--preset studio';            
                $known_encoder_values[0xC0][88][1][1][1][2][0]     = '--preset cd';                
                $known_encoder_values[0xC0][58][2][2][3][2][19600] = '--preset cd';                
                $known_encoder_values[0xC0][58][2][2][3][2][19500] = '--preset cd';                
                $known_encoder_values[0xC0][57][2][1][3][4][19500] = '--preset cd';                
                $known_encoder_values[0xA0][78][1][1][3][2][18000] = '--preset hifi';              
                $known_encoder_values[0xA0][58][2][2][3][2][18000] = '--preset hifi';              
                $known_encoder_values[0xA0][57][2][1][3][4][18000] = '--preset hifi';              
                $known_encoder_values[0x80][67][1][1][3][2][18000] = '--preset tape';              
                $known_encoder_values[0x80][67][1][1][3][2][15000] = '--preset radio';             
                $known_encoder_values[0x70][67][1][1][3][2][15000] = '--preset fm';                
                $known_encoder_values[0x70][58][2][2][3][2][16000] = '--preset tape/radio/fm';     
                $known_encoder_values[0x70][57][2][1][3][4][16000] = '--preset tape/radio/fm';     
                $known_encoder_values[0x38][58][2][2][0][2][10000] = '--preset voice';             
                $known_encoder_values[0x38][57][2][1][0][4][15000] = '--preset voice';             
                $known_encoder_values[0x38][57][2][1][0][4][16000] = '--preset voice';             
                $known_encoder_values[0x28][65][1][1][0][2][7500]  = '--preset mw-us';             
                $known_encoder_values[0x28][65][1][1][0][2][7600]  = '--preset mw-us';             
                $known_encoder_values[0x28][58][2][2][0][2][7000]  = '--preset mw-us';             
                $known_encoder_values[0x28][57][2][1][0][4][10500] = '--preset mw-us';             
                $known_encoder_values[0x28][57][2][1][0][4][11200] = '--preset mw-us';             
                $known_encoder_values[0x28][57][2][1][0][4][8800]  = '--preset mw-us';             
                $known_encoder_values[0x18][58][2][2][0][2][4000]  = '--preset phon+/lw/mw-eu/sw'; 
                $known_encoder_values[0x18][58][2][2][0][2][3900]  = '--preset phon+/lw/mw-eu/sw'; 
                $known_encoder_values[0x18][57][2][1][0][4][5900]  = '--preset phon+/lw/mw-eu/sw'; 
                $known_encoder_values[0x18][57][2][1][0][4][6200]  = '--preset phon+/lw/mw-eu/sw'; 
                $known_encoder_values[0x18][57][2][1][0][4][3200]  = '--preset phon+/lw/mw-eu/sw'; 
                $known_encoder_values[0x10][58][2][2][0][2][3800]  = '--preset phone';             
                $known_encoder_values[0x10][58][2][2][0][2][3700]  = '--preset phone';             
                $known_encoder_values[0x10][57][2][1][0][4][5600]  = '--preset phone';             
            }

            if (isset($known_encoder_values[$thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate']][$thisfile_mpeg_audio_lame['vbr_quality']][$thisfile_mpeg_audio_lame['raw']['vbr_method']][$thisfile_mpeg_audio_lame['raw']['noise_shaping']][$thisfile_mpeg_audio_lame['raw']['stereo_mode']][$thisfile_mpeg_audio_lame['ath_type']][$thisfile_mpeg_audio_lame['lowpass_frequency']])) {

                $encoder_options = $known_encoder_values[$thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate']][$thisfile_mpeg_audio_lame['vbr_quality']][$thisfile_mpeg_audio_lame['raw']['vbr_method']][$thisfile_mpeg_audio_lame['raw']['noise_shaping']][$thisfile_mpeg_audio_lame['raw']['stereo_mode']][$thisfile_mpeg_audio_lame['ath_type']][$thisfile_mpeg_audio_lame['lowpass_frequency']];

            } elseif (isset($known_encoder_values['**'][$thisfile_mpeg_audio_lame['vbr_quality']][$thisfile_mpeg_audio_lame['raw']['vbr_method']][$thisfile_mpeg_audio_lame['raw']['noise_shaping']][$thisfile_mpeg_audio_lame['raw']['stereo_mode']][$thisfile_mpeg_audio_lame['ath_type']][$thisfile_mpeg_audio_lame['lowpass_frequency']])) {

                $encoder_options = $known_encoder_values['**'][$thisfile_mpeg_audio_lame['vbr_quality']][$thisfile_mpeg_audio_lame['raw']['vbr_method']][$thisfile_mpeg_audio_lame['raw']['noise_shaping']][$thisfile_mpeg_audio_lame['raw']['stereo_mode']][$thisfile_mpeg_audio_lame['ath_type']][$thisfile_mpeg_audio_lame['lowpass_frequency']];

            } elseif ($info['audio']['bitrate_mode'] == 'vbr') {

                
                


                $lame_v_value = 10 - ceil($thisfile_mpeg_audio_lame['vbr_quality'] / 10);
                $lame_q_value = 100 - $thisfile_mpeg_audio_lame['vbr_quality'] - ($lame_v_value * 10);
                $encoder_options = '-V'.$lame_v_value.' -q'.$lame_q_value;

            } elseif ($info['audio']['bitrate_mode'] == 'cbr') {

                $encoder_options = strtoupper($info['audio']['bitrate_mode']).ceil($info['audio']['bitrate'] / 1000);

            } else {

                $encoder_options = strtoupper($info['audio']['bitrate_mode']);

            }

        } elseif (!empty($thisfile_mpeg_audio_lame['bitrate_abr'])) {

            $encoder_options = 'ABR'.$thisfile_mpeg_audio_lame['bitrate_abr'];

        } elseif (!empty($info['audio']['bitrate'])) {

            if ($info['audio']['bitrate_mode'] == 'cbr') {
                $encoder_options = strtoupper($info['audio']['bitrate_mode']).ceil($info['audio']['bitrate'] / 1000);
            } else {
                $encoder_options = strtoupper($info['audio']['bitrate_mode']);
            }

        }
        if (!empty($thisfile_mpeg_audio_lame['bitrate_min'])) {
            $encoder_options .= ' -b'.$thisfile_mpeg_audio_lame['bitrate_min'];
        }

        if (@$thisfile_mpeg_audio_lame['encoding_flags']['nogap_prev'] || @$thisfile_mpeg_audio_lame['encoding_flags']['nogap_next']) {
            $encoder_options .= ' --nogap';
        }

        if (!empty($thisfile_mpeg_audio_lame['lowpass_frequency'])) {
            $exploded_options = explode(' ', $encoder_options, 4);
            if ($exploded_options[0] == '--r3mix') {
                $exploded_options[1] = 'r3mix';
            }
            switch ($exploded_options[0]) {
                case '--preset':
                case '--alt-preset':
                case '--r3mix':
                    if ($exploded_options[1] == 'fast') {
                        $exploded_options[1] .= ' '.$exploded_options[2];
                    }
                    switch ($exploded_options[1]) {
                        case 'portable':
                        case 'medium':
                        case 'standard':
                        case 'extreme':
                        case 'insane':
                        case 'fast portable':
                        case 'fast medium':
                        case 'fast standard':
                        case 'fast extreme':
                        case 'fast insane':
                        case 'r3mix':
                            static $expected_lowpass = array (
                                    'insane|20500'        => 20500,
                                    'insane|20600'        => 20600,  
                                    'medium|18000'        => 18000,
                                    'fast medium|18000'   => 18000,
                                    'extreme|19500'       => 19500,  
                                    'extreme|19600'       => 19600,  
                                    'fast extreme|19500'  => 19500,  
                                    'fast extreme|19600'  => 19600,  
                                    'standard|19000'      => 19000,
                                    'fast standard|19000' => 19000,
                                    'r3mix|19500'         => 19500,  
                                    'r3mix|19600'         => 19600,  
                                    'r3mix|18000'         => 18000,  
                                );
                            if (!isset($expected_lowpass[$exploded_options[1].'|'.$thisfile_mpeg_audio_lame['lowpass_frequency']]) && ($thisfile_mpeg_audio_lame['lowpass_frequency'] < 22050) && (round($thisfile_mpeg_audio_lame['lowpass_frequency'] / 1000) < round($thisfile_mpeg_audio['sample_rate'] / 2000))) {
                                $encoder_options .= ' --lowpass '.$thisfile_mpeg_audio_lame['lowpass_frequency'];
                            }
                            break;

                        default:
                            break;
                    }
                    break;
            }
        }

        if (isset($thisfile_mpeg_audio_lame['raw']['source_sample_freq'])) {
            if (($thisfile_mpeg_audio['sample_rate'] == 44100) && ($thisfile_mpeg_audio_lame['raw']['source_sample_freq'] != 1)) {
                $encoder_options .= ' --resample 44100';
            } elseif (($thisfile_mpeg_audio['sample_rate'] == 48000) && ($thisfile_mpeg_audio_lame['raw']['source_sample_freq'] != 2)) {
                $encoder_options .= ' --resample 48000';
            } elseif ($thisfile_mpeg_audio['sample_rate'] < 44100) {
                switch ($thisfile_mpeg_audio_lame['raw']['source_sample_freq']) {
                    case 0: 
                        
                        break;
                    case 1: 
                    case 2: 
                    case 3: 
                        $exploded_options = explode(' ', $encoder_options, 4);
                        switch ($exploded_options[0]) {
                            case '--preset':
                            case '--alt-preset':
                                switch ($exploded_options[1]) {
                                    case 'fast':
                                    case 'portable':
                                    case 'medium':
                                    case 'standard':
                                    case 'extreme':
                                    case 'insane':
                                        $encoder_options .= ' --resample '.$thisfile_mpeg_audio['sample_rate'];
                                        break;

                                    default:
                                        static $expected_resampled_rate = array (
                                                'phon+/lw/mw-eu/sw|16000' => 16000,
                                                'mw-us|24000'             => 24000, 
                                                'mw-us|32000'             => 32000, 
                                                'mw-us|16000'             => 16000, 
                                                'phone|16000'             => 16000,
                                                'phone|11025'             => 11025, 
                                                'radio|32000'             => 32000, 
                                                'fm/radio|32000'          => 32000, 
                                                'fm|32000'                => 32000, 
                                                'voice|32000'             => 32000);
                                        if (!isset($expected_resampled_rate[$exploded_options[1].'|'.$thisfile_mpeg_audio['sample_rate']])) {
                                            $encoder_options .= ' --resample '.$thisfile_mpeg_audio['sample_rate'];
                                        }
                                        break;
                                }
                                break;

                            case '--r3mix':
                            default:
                                $encoder_options .= ' --resample '.$thisfile_mpeg_audio['sample_rate'];
                                break;
                        }
                        break;
                }
            }
        }
        if (empty($encoder_options) && !empty($info['audio']['bitrate']) && !empty($info['audio']['bitrate_mode'])) {
            
            $encoder_options = strtoupper($info['audio']['bitrate_mode']);
        }

        return $encoder_options;
    }



    public function decodeMPEGaudioHeader($fd, $offset, &$info, $recursive_search=true, $scan_as_cbr=false, $fast_mpeg_header_scan=false) {

        static $mpeg_audio_version_lookup;
        static $mpeg_audio_layer_lookup;
        static $mpeg_audio_bitrate_lookup;
        static $mpeg_audio_frequency_lookup;
        static $mpeg_audio_channel_mode_lookup;
        static $mpeg_audio_mode_extension_lookup;
        static $mpeg_audio_emphasis_lookup;
        if (empty($mpeg_audio_version_lookup)) {
            $mpeg_audio_version_lookup        = getid3_mp3::MPEGaudioVersionarray();
            $mpeg_audio_layer_lookup          = getid3_mp3::MPEGaudioLayerarray();
            $mpeg_audio_bitrate_lookup        = getid3_mp3::MPEGaudioBitratearray();
            $mpeg_audio_frequency_lookup      = getid3_mp3::MPEGaudioFrequencyarray();
            $mpeg_audio_channel_mode_lookup   = getid3_mp3::MPEGaudioChannelModearray();
            $mpeg_audio_mode_extension_lookup = getid3_mp3::MPEGaudioModeExtensionarray();
            $mpeg_audio_emphasis_lookup       = getid3_mp3::MPEGaudioEmphasisarray();
        }

        if ($offset >= $info['avdataend']) {

            
            return;

        }
        fseek($fd, $offset, SEEK_SET);
        $header_string = fread($fd, 226); 

        
        
        
        
        

        $head4 = substr($header_string, 0, 4);

        if (isset($mpeg_audio_header_decode_cache[$head4])) {
            $mpeg_header_raw_array= $mpeg_audio_header_decode_cache[$head4];
        } else {
            $mpeg_header_raw_array = getid3_mp3::MPEGaudioHeaderDecode($head4);
            $mpeg_audio_header_decode_cache[$head4] = $mpeg_header_raw_array;
        }

        
        if (!isset($mpeg_audio_header_valid_cache[$head4])) {
            $mpeg_audio_header_valid_cache[$head4] = getid3_mp3::MPEGaudioHeaderValid($mpeg_header_raw_array, false, false);
        }

        
        if (!isset($info['mpeg']['audio'])) {
            $info['mpeg']['audio'] = array ();
        }
        $thisfile_mpeg_audio = &$info['mpeg']['audio'];


        if ($mpeg_audio_header_valid_cache[$head4]) {
            $thisfile_mpeg_audio['raw'] = $mpeg_header_raw_array;
        } else {

            
            return;
        }

        if (!$fast_mpeg_header_scan) {

            $thisfile_mpeg_audio['version']       = $mpeg_audio_version_lookup[$thisfile_mpeg_audio['raw']['version']];
            $thisfile_mpeg_audio['layer']         = $mpeg_audio_layer_lookup[$thisfile_mpeg_audio['raw']['layer']];

            $thisfile_mpeg_audio['channelmode']   = $mpeg_audio_channel_mode_lookup[$thisfile_mpeg_audio['raw']['channelmode']];
            $thisfile_mpeg_audio['channels']      = (($thisfile_mpeg_audio['channelmode'] == 'mono') ? 1 : 2);
            $thisfile_mpeg_audio['sample_rate']   = $mpeg_audio_frequency_lookup[$thisfile_mpeg_audio['version']][$thisfile_mpeg_audio['raw']['sample_rate']];
            $thisfile_mpeg_audio['protection']    = !$thisfile_mpeg_audio['raw']['protection'];
            $thisfile_mpeg_audio['private']       = (bool) $thisfile_mpeg_audio['raw']['private'];
            $thisfile_mpeg_audio['modeextension'] = $mpeg_audio_mode_extension_lookup[$thisfile_mpeg_audio['layer']][$thisfile_mpeg_audio['raw']['modeextension']];
            $thisfile_mpeg_audio['copyright']     = (bool) $thisfile_mpeg_audio['raw']['copyright'];
            $thisfile_mpeg_audio['original']      = (bool) $thisfile_mpeg_audio['raw']['original'];
            $thisfile_mpeg_audio['emphasis']      = $mpeg_audio_emphasis_lookup[$thisfile_mpeg_audio['raw']['emphasis']];

            $info['audio']['channels']    = $thisfile_mpeg_audio['channels'];
            $info['audio']['sample_rate'] = $thisfile_mpeg_audio['sample_rate'];

            if ($thisfile_mpeg_audio['protection']) {
                $thisfile_mpeg_audio['crc'] = getid3_lib::BigEndian2Int(substr($header_string, 4, 2));
            }

        }

        if ($thisfile_mpeg_audio['raw']['bitrate'] == 15) {
            
            $this->getid3->warning('Invalid bitrate index (15), this is a known bug in free-format MP3s encoded by LAME v3.90 - 3.93.1');
            $thisfile_mpeg_audio['raw']['bitrate'] = 0;
        }
        $thisfile_mpeg_audio['padding'] = (bool) $thisfile_mpeg_audio['raw']['padding'];
        $thisfile_mpeg_audio['bitrate'] = $mpeg_audio_bitrate_lookup[$thisfile_mpeg_audio['version']][$thisfile_mpeg_audio['layer']][$thisfile_mpeg_audio['raw']['bitrate']];

        if (($thisfile_mpeg_audio['bitrate'] == 'free') && ($offset == $info['avdataoffset'])) {
            
            
            $recursive_search = false;
        }

        
        if (!$fast_mpeg_header_scan && ($thisfile_mpeg_audio['layer'] == '2')) {

            $info['audio']['dataformat'] = 'mp2';
            switch ($thisfile_mpeg_audio['channelmode']) {

                case 'mono':
                    if (($thisfile_mpeg_audio['bitrate'] == 'free') || ($thisfile_mpeg_audio['bitrate'] <= 192000)) {
                        
                    } else {

                        
                        return;
                    }
                    break;

                case 'stereo':
                case 'joint stereo':
                case 'dual channel':
                    if (($thisfile_mpeg_audio['bitrate'] == 'free') || ($thisfile_mpeg_audio['bitrate'] == 64000) || ($thisfile_mpeg_audio['bitrate'] >= 96000)) {
                        
                    } else {

                        
                        return;
                    }
                    break;

            }

        }


        if ($info['audio']['sample_rate'] > 0) {
            $thisfile_mpeg_audio['framelength'] = getid3_mp3::MPEGaudioFrameLength($thisfile_mpeg_audio['bitrate'], $thisfile_mpeg_audio['version'], $thisfile_mpeg_audio['layer'], (int) $thisfile_mpeg_audio['padding'], $info['audio']['sample_rate']);
        }

        $next_frame_test_offset = $offset + 1;
        if ($thisfile_mpeg_audio['bitrate'] != 'free') {

            $info['audio']['bitrate'] = $thisfile_mpeg_audio['bitrate'];

            if (isset($thisfile_mpeg_audio['framelength'])) {
                $next_frame_test_offset = $offset + $thisfile_mpeg_audio['framelength'];
            } else {

                
                return;
            }

        }

        $expected_number_of_audio_bytes = 0;

        
        

        if (substr($header_string, 4 + 32, 4) == 'VBRI') {
            
            

            $thisfile_mpeg_audio['bitrate_mode'] = 'vbr';
            $thisfile_mpeg_audio['VBR_method']   = 'Fraunhofer';
            $info['audio']['codec']                = 'Fraunhofer';

            $side_info_data = substr($header_string, 4 + 2, 32);

            $fraunhofer_vbr_offset = 36;

            $thisfile_mpeg_audio['VBR_encoder_version']     = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset +  4, 2)); 
            $thisfile_mpeg_audio['VBR_encoder_delay']       = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset +  6, 2)); 
            $thisfile_mpeg_audio['VBR_quality']             = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset +  8, 2)); 
            $thisfile_mpeg_audio['VBR_bytes']               = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 10, 4)); 
            $thisfile_mpeg_audio['VBR_frames']              = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 14, 4)); 
            $thisfile_mpeg_audio['VBR_seek_offsets']        = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 18, 2)); 
            $thisfile_mpeg_audio['VBR_seek_scale']          = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 20, 2)); 
            $thisfile_mpeg_audio['VBR_entry_bytes']         = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 22, 2)); 
            $thisfile_mpeg_audio['VBR_entry_frames']        = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset + 24, 2)); 

            $expected_number_of_audio_bytes = $thisfile_mpeg_audio['VBR_bytes'];

            $previous_byte_offset = $offset;
            for ($i = 0; $i < $thisfile_mpeg_audio['VBR_seek_offsets']; $i++) {
                $fraunhofer_offset_n = getid3_lib::BigEndian2Int(substr($header_string, $fraunhofer_vbr_offset, $thisfile_mpeg_audio['VBR_entry_bytes']));
                $fraunhofer_vbr_offset += $thisfile_mpeg_audio['VBR_entry_bytes'];
                $thisfile_mpeg_audio['VBR_offsets_relative'][$i] = ($fraunhofer_offset_n * $thisfile_mpeg_audio['VBR_seek_scale']);
                $thisfile_mpeg_audio['VBR_offsets_absolute'][$i] = ($fraunhofer_offset_n * $thisfile_mpeg_audio['VBR_seek_scale']) + $previous_byte_offset;
                $previous_byte_offset += $fraunhofer_offset_n;
            }


        } else {

            
            

            $vbr_id_offset = getid3_mp3::XingVBRidOffset($thisfile_mpeg_audio['version'], $thisfile_mpeg_audio['channelmode']);
            $side_info_data = substr($header_string, 4 + 2, $vbr_id_offset - 4);

            if ((substr($header_string, $vbr_id_offset, strlen('Xing')) == 'Xing') || (substr($header_string, $vbr_id_offset, strlen('Info')) == 'Info')) {
                
                
                

                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                


                
                $thisfile_mpeg_audio['bitrate_mode'] = 'vbr';
                $thisfile_mpeg_audio['VBR_method']   = 'Xing';

                $thisfile_mpeg_audio['xing_flags_raw'] = getid3_lib::BigEndian2Int(substr($header_string, $vbr_id_offset + 4, 4));

                $thisfile_mpeg_audio['xing_flags']['frames']    = (bool) ($thisfile_mpeg_audio['xing_flags_raw'] & 0x00000001);
                $thisfile_mpeg_audio['xing_flags']['bytes']     = (bool) ($thisfile_mpeg_audio['xing_flags_raw'] & 0x00000002);
                $thisfile_mpeg_audio['xing_flags']['toc']       = (bool) ($thisfile_mpeg_audio['xing_flags_raw'] & 0x00000004);
                $thisfile_mpeg_audio['xing_flags']['vbr_scale'] = (bool) ($thisfile_mpeg_audio['xing_flags_raw'] & 0x00000008);

                if ($thisfile_mpeg_audio['xing_flags']['frames']) {
                    $thisfile_mpeg_audio['VBR_frames'] = getid3_lib::BigEndian2Int(substr($header_string, $vbr_id_offset +  8, 4));
                }
                if ($thisfile_mpeg_audio['xing_flags']['bytes']) {
                    $thisfile_mpeg_audio['VBR_bytes']  = getid3_lib::BigEndian2Int(substr($header_string, $vbr_id_offset + 12, 4));
                }

                if (!empty($thisfile_mpeg_audio['VBR_frames']) && !empty($thisfile_mpeg_audio['VBR_bytes'])) {

                    $frame_lengthfloat = $thisfile_mpeg_audio['VBR_bytes'] / $thisfile_mpeg_audio['VBR_frames'];

                    if ($thisfile_mpeg_audio['layer'] == '1') {
                        
                        $info['audio']['bitrate'] = ($frame_lengthfloat / 4) * $thisfile_mpeg_audio['sample_rate'] * (2 / $info['audio']['channels']) / 12;
                    } else {
                        
                        $info['audio']['bitrate'] = $frame_lengthfloat * $thisfile_mpeg_audio['sample_rate'] * (2 / $info['audio']['channels']) / 144;
                    }
                    $thisfile_mpeg_audio['framelength'] = floor($frame_lengthfloat);
                }

                if ($thisfile_mpeg_audio['xing_flags']['toc']) {
                    $lame_toc_data = substr($header_string, $vbr_id_offset + 16, 100);
                    for ($i = 0; $i < 100; $i++) {
                        $thisfile_mpeg_audio['toc'][$i] = ord($lame_toc_data{$i});
                    }
                }
                if ($thisfile_mpeg_audio['xing_flags']['vbr_scale']) {
                    $thisfile_mpeg_audio['VBR_scale'] = getid3_lib::BigEndian2Int(substr($header_string, $vbr_id_offset + 116, 4));
                }


                
                if (substr($header_string, $vbr_id_offset + 120, 4) == 'LAME') {

                    
                    $thisfile_mpeg_audio['LAME'] = array ();
                    $thisfile_mpeg_audio_lame    = &$thisfile_mpeg_audio['LAME'];


                    $thisfile_mpeg_audio_lame['long_version']  = substr($header_string, $vbr_id_offset + 120, 20);
                    $thisfile_mpeg_audio_lame['short_version'] = substr($thisfile_mpeg_audio_lame['long_version'], 0, 9);

                    if ($thisfile_mpeg_audio_lame['short_version'] >= 'LAME3.90') {

                        
                        unset($thisfile_mpeg_audio_lame['long_version']);

                        
                        

                        
                        
                        
                        $lame_tag_offset_contant = $vbr_id_offset - 0x24;

                        
                        $thisfile_mpeg_audio_lame['RGAD']    = array ('track'=>array(), 'album'=>array());
                        $thisfile_mpeg_audio_lame_rgad       = &$thisfile_mpeg_audio_lame['RGAD'];
                        $thisfile_mpeg_audio_lame_rgad_track = &$thisfile_mpeg_audio_lame_rgad['track'];
                        $thisfile_mpeg_audio_lame_rgad_album = &$thisfile_mpeg_audio_lame_rgad['album'];
                        $thisfile_mpeg_audio_lame['raw']     = array ();
                        $thisfile_mpeg_audio_lame_raw        = &$thisfile_mpeg_audio_lame['raw'];

                        
                        
                        
                        unset($thisfile_mpeg_audio['VBR_scale']);
                        $thisfile_mpeg_audio_lame['vbr_quality'] = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0x9B, 1));

                        
                        $thisfile_mpeg_audio_lame['short_version'] = substr($header_string, $lame_tag_offset_contant + 0x9C, 9);

                        
                        $lame_tagRevisionVBRmethod = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xA5, 1));

                        $thisfile_mpeg_audio_lame['tag_revision']      = ($lame_tagRevisionVBRmethod & 0xF0) >> 4;
                        $thisfile_mpeg_audio_lame_raw['vbr_method'] =  $lame_tagRevisionVBRmethod & 0x0F;
                        $thisfile_mpeg_audio_lame['vbr_method']        = getid3_mp3::LAMEvbrMethodLookup($thisfile_mpeg_audio_lame_raw['vbr_method']);
                        $thisfile_mpeg_audio['bitrate_mode']           = substr($thisfile_mpeg_audio_lame['vbr_method'], 0, 3); 

                        
                        $thisfile_mpeg_audio_lame['lowpass_frequency'] = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xA6, 1)) * 100;

                        
                        
                        
                        if ($thisfile_mpeg_audio_lame['short_version'] >= 'LAME3.94b') {
                            
                            
                            $thisfile_mpeg_audio_lame_rgad['peak_amplitude'] = (float) ((getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xA7, 4))) / 8388608);
                        } else {
                            
                            
                            $thisfile_mpeg_audio_lame_rgad['peak_amplitude'] = getid3_lib::LittleEndian2Float(substr($header_string, $lame_tag_offset_contant + 0xA7, 4));
                        }
                        if ($thisfile_mpeg_audio_lame_rgad['peak_amplitude'] == 0) {
                            unset($thisfile_mpeg_audio_lame_rgad['peak_amplitude']);
                        } else {
                            $thisfile_mpeg_audio_lame_rgad['peak_db'] = 20 * log10($thisfile_mpeg_audio_lame_rgad['peak_amplitude']);
                        }

                        $thisfile_mpeg_audio_lame_raw['RGAD_track']      =   getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xAB, 2));
                        $thisfile_mpeg_audio_lame_raw['RGAD_album']      =   getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xAD, 2));


                        if ($thisfile_mpeg_audio_lame_raw['RGAD_track'] != 0) {

                            $thisfile_mpeg_audio_lame_rgad_track['raw']['name']        = ($thisfile_mpeg_audio_lame_raw['RGAD_track'] & 0xE000) >> 13;
                            $thisfile_mpeg_audio_lame_rgad_track['raw']['originator']  = ($thisfile_mpeg_audio_lame_raw['RGAD_track'] & 0x1C00) >> 10;
                            $thisfile_mpeg_audio_lame_rgad_track['raw']['sign_bit']    = ($thisfile_mpeg_audio_lame_raw['RGAD_track'] & 0x0200) >> 9;
                            $thisfile_mpeg_audio_lame_rgad_track['raw']['gain_adjust'] =  $thisfile_mpeg_audio_lame_raw['RGAD_track'] & 0x01FF;
                            $thisfile_mpeg_audio_lame_rgad_track['name']       = getid3_lib_replaygain::NameLookup($thisfile_mpeg_audio_lame_rgad_track['raw']['name']);
                            $thisfile_mpeg_audio_lame_rgad_track['originator'] = getid3_lib_replaygain::OriginatorLookup($thisfile_mpeg_audio_lame_rgad_track['raw']['originator']);
                            $thisfile_mpeg_audio_lame_rgad_track['gain_db']    = getid3_lib_replaygain::AdjustmentLookup($thisfile_mpeg_audio_lame_rgad_track['raw']['gain_adjust'], $thisfile_mpeg_audio_lame_rgad_track['raw']['sign_bit']);

                            if (!empty($thisfile_mpeg_audio_lame_rgad['peak_amplitude'])) {
                                $info['replay_gain']['track']['peak']   = $thisfile_mpeg_audio_lame_rgad['peak_amplitude'];
                            }
                            $info['replay_gain']['track']['originator'] = $thisfile_mpeg_audio_lame_rgad_track['originator'];
                            $info['replay_gain']['track']['adjustment'] = $thisfile_mpeg_audio_lame_rgad_track['gain_db'];
                        } else {
                            unset($thisfile_mpeg_audio_lame_rgad['track']);
                        }
                        if ($thisfile_mpeg_audio_lame_raw['RGAD_album'] != 0) {

                            $thisfile_mpeg_audio_lame_rgad_album['raw']['name']        = ($thisfile_mpeg_audio_lame_raw['RGAD_album'] & 0xE000) >> 13;
                            $thisfile_mpeg_audio_lame_rgad_album['raw']['originator']  = ($thisfile_mpeg_audio_lame_raw['RGAD_album'] & 0x1C00) >> 10;
                            $thisfile_mpeg_audio_lame_rgad_album['raw']['sign_bit']    = ($thisfile_mpeg_audio_lame_raw['RGAD_album'] & 0x0200) >> 9;
                            $thisfile_mpeg_audio_lame_rgad_album['raw']['gain_adjust'] =  $thisfile_mpeg_audio_lame_raw['RGAD_album'] & 0x01FF;
                            $thisfile_mpeg_audio_lame_rgad_album['name']       = getid3_lib_replaygain::NameLookup($thisfile_mpeg_audio_lame_rgad_album['raw']['name']);
                            $thisfile_mpeg_audio_lame_rgad_album['originator'] = getid3_lib_replaygain::OriginatorLookup($thisfile_mpeg_audio_lame_rgad_album['raw']['originator']);
                            $thisfile_mpeg_audio_lame_rgad_album['gain_db']    = getid3_lib_replaygain::AdjustmentLookup($thisfile_mpeg_audio_lame_rgad_album['raw']['gain_adjust'], $thisfile_mpeg_audio_lame_rgad_album['raw']['sign_bit']);

                            if (!empty($thisfile_mpeg_audio_lame_rgad['peak_amplitude'])) {
                                $info['replay_gain']['album']['peak']   = $thisfile_mpeg_audio_lame_rgad['peak_amplitude'];
                            }
                            $info['replay_gain']['album']['originator'] = $thisfile_mpeg_audio_lame_rgad_album['originator'];
                            $info['replay_gain']['album']['adjustment'] = $thisfile_mpeg_audio_lame_rgad_album['gain_db'];
                        } else {
                            unset($thisfile_mpeg_audio_lame_rgad['album']);
                        }
                        if (empty($thisfile_mpeg_audio_lame_rgad)) {
                            unset($thisfile_mpeg_audio_lame['RGAD']);
                        }


                        
                        $encoding_flags_ath_type = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xAF, 1));
                        $thisfile_mpeg_audio_lame['encoding_flags']['nspsytune']   = (bool) ($encoding_flags_ath_type & 0x10);
                        $thisfile_mpeg_audio_lame['encoding_flags']['nssafejoint'] = (bool) ($encoding_flags_ath_type & 0x20);
                        $thisfile_mpeg_audio_lame['encoding_flags']['nogap_next']  = (bool) ($encoding_flags_ath_type & 0x40);
                        $thisfile_mpeg_audio_lame['encoding_flags']['nogap_prev']  = (bool) ($encoding_flags_ath_type & 0x80);
                        $thisfile_mpeg_audio_lame['ath_type']                      =         $encoding_flags_ath_type & 0x0F;

                        
                        $thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate'] = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB0, 1));
                        if ($thisfile_mpeg_audio_lame_raw['vbr_method'] == 2) { 
                            $thisfile_mpeg_audio_lame['bitrate_abr'] = $thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate'];
                        } elseif ($thisfile_mpeg_audio_lame_raw['vbr_method'] == 1) { 
                            
                        } elseif ($thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate'] > 0) { 
                            $thisfile_mpeg_audio_lame['bitrate_min'] = $thisfile_mpeg_audio_lame['raw']['abrbitrate_minbitrate'];
                        }

                        
                        $encoder_delays = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB1, 3));
                        $thisfile_mpeg_audio_lame['encoder_delay'] = ($encoder_delays & 0xFFF000) >> 12;
                        $thisfile_mpeg_audio_lame['end_padding']   =  $encoder_delays & 0x000FFF;

                        
                        $misc_byte = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB4, 1));
                        $thisfile_mpeg_audio_lame_raw['noise_shaping']       = ($misc_byte & 0x03);
                        $thisfile_mpeg_audio_lame_raw['stereo_mode']         = ($misc_byte & 0x1C) >> 2;
                        $thisfile_mpeg_audio_lame_raw['not_optimal_quality'] = ($misc_byte & 0x20) >> 5;
                        $thisfile_mpeg_audio_lame_raw['source_sample_freq']  = ($misc_byte & 0xC0) >> 6;
                        $thisfile_mpeg_audio_lame['noise_shaping']           = $thisfile_mpeg_audio_lame_raw['noise_shaping'];
                        $thisfile_mpeg_audio_lame['stereo_mode']             = getid3_mp3::LAMEmiscStereoModeLookup($thisfile_mpeg_audio_lame_raw['stereo_mode']);
                        $thisfile_mpeg_audio_lame['not_optimal_quality']     = (bool) $thisfile_mpeg_audio_lame_raw['not_optimal_quality'];
                        $thisfile_mpeg_audio_lame['source_sample_freq']      = getid3_mp3::LAMEmiscSourceSampleFrequencyLookup($thisfile_mpeg_audio_lame_raw['source_sample_freq']);

                        
                        $thisfile_mpeg_audio_lame_raw['mp3_gain']   = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB5, 1), false, true);
                        $thisfile_mpeg_audio_lame['mp3_gain_db']     = (20 * log10(2) / 4) * $thisfile_mpeg_audio_lame_raw['mp3_gain'];
                        $thisfile_mpeg_audio_lame['mp3_gain_factor'] = pow(2, ($thisfile_mpeg_audio_lame['mp3_gain_db'] / 6));

                        
                        $PresetSurroundBytes = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB6, 2));
                        
                        $thisfile_mpeg_audio_lame_raw['surround_info'] = ($PresetSurroundBytes & 0x3800);
                        $thisfile_mpeg_audio_lame['surround_info']     = getid3_mp3::LAMEsurroundInfoLookup($thisfile_mpeg_audio_lame_raw['surround_info']);
                        $thisfile_mpeg_audio_lame['preset_used_id']    = ($PresetSurroundBytes & 0x07FF);
                        $thisfile_mpeg_audio_lame['preset_used']       = getid3_mp3::LAMEpresetUsedLookup($thisfile_mpeg_audio_lame);
                        if (!empty($thisfile_mpeg_audio_lame['preset_used_id']) && empty($thisfile_mpeg_audio_lame['preset_used'])) {
                            $this->getid3->warning('Unknown LAME preset used ('.$thisfile_mpeg_audio_lame['preset_used_id'].') - please report to info@getid3.org');
                        }
                        if (($thisfile_mpeg_audio_lame['short_version'] == 'LAME3.90.') && !empty($thisfile_mpeg_audio_lame['preset_used_id'])) {
                            
                            $thisfile_mpeg_audio_lame['short_version'] = 'LAME3.90.3';
                        }

                        
                        $thisfile_mpeg_audio_lame['audio_bytes'] = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xB8, 4));
                        $expected_number_of_audio_bytes = (($thisfile_mpeg_audio_lame['audio_bytes'] > 0) ? $thisfile_mpeg_audio_lame['audio_bytes'] : $thisfile_mpeg_audio['VBR_bytes']);

                        
                        $thisfile_mpeg_audio_lame['music_crc']    = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xBC, 2));

                        
                        $thisfile_mpeg_audio_lame['lame_tag_crc'] = getid3_lib::BigEndian2Int(substr($header_string, $lame_tag_offset_contant + 0xBE, 2));


                        
                        if ($thisfile_mpeg_audio_lame_raw['vbr_method'] == 1) {

                            $thisfile_mpeg_audio['bitrate_mode'] = 'cbr';
                            $thisfile_mpeg_audio['bitrate'] = getid3_mp3::ClosestStandardMP3Bitrate($thisfile_mpeg_audio['bitrate']);
                            $info['audio']['bitrate'] = $thisfile_mpeg_audio['bitrate'];

                        }

                    }
                }

            } else {

                
                $thisfile_mpeg_audio['bitrate_mode'] = 'cbr';
                if ($recursive_search) {
                    $thisfile_mpeg_audio['bitrate_mode'] = 'vbr';
                    if (getid3_mp3::RecursiveFrameScanning($fd, $info, $offset, $next_frame_test_offset, true)) {
                        $recursive_search = false;
                        $thisfile_mpeg_audio['bitrate_mode'] = 'cbr';
                    }
                    if ($thisfile_mpeg_audio['bitrate_mode'] == 'vbr') {
                        $this->getid3->warning('VBR file with no VBR header. Bitrate values calculated from actual frame bitrates.');
                    }
                }

            }

        }

        if (($expected_number_of_audio_bytes > 0) && ($expected_number_of_audio_bytes != ($info['avdataend'] - $info['avdataoffset']))) {
            if ($expected_number_of_audio_bytes > ($info['avdataend'] - $info['avdataoffset'])) {
                if (($expected_number_of_audio_bytes - ($info['avdataend'] - $info['avdataoffset'])) == 1) {
                    $this->getid3->warning('Last byte of data truncated (this is a known bug in Meracl ID3 Tag Writer before v1.3.5)');
                } else {
                    $this->getid3->warning('Probable truncated file: expecting '.$expected_number_of_audio_bytes.' bytes of audio data, only found '.($info['avdataend'] - $info['avdataoffset']).' (short by '.($expected_number_of_audio_bytes - ($info['avdataend'] - $info['avdataoffset'])).' bytes)');
                }
            } else {
                if ((($info['avdataend'] - $info['avdataoffset']) - $expected_number_of_audio_bytes) == 1) {
                        $info['avdataend']--;
                } else {
                    $this->getid3->warning('Too much data in file: expecting '.$expected_number_of_audio_bytes.' bytes of audio data, found '.($info['avdataend'] - $info['avdataoffset']).' ('.(($info['avdataend'] - $info['avdataoffset']) - $expected_number_of_audio_bytes).' bytes too many)');
                }
            }
        }

        if (($thisfile_mpeg_audio['bitrate'] == 'free') && empty($info['audio']['bitrate'])) {
            if (($offset == $info['avdataoffset']) && empty($thisfile_mpeg_audio['VBR_frames'])) {
                $frame_byte_length = getid3_mp3::FreeFormatFrameLength($fd, $offset, $info, true);
                if ($frame_byte_length > 0) {
                    $thisfile_mpeg_audio['framelength'] = $frame_byte_length;
                    if ($thisfile_mpeg_audio['layer'] == '1') {
                        
                        $info['audio']['bitrate'] = ((($frame_byte_length / 4) - intval($thisfile_mpeg_audio['padding'])) * $thisfile_mpeg_audio['sample_rate']) / 12;
                    } else {
                        
                        $info['audio']['bitrate'] = (($frame_byte_length - intval($thisfile_mpeg_audio['padding'])) * $thisfile_mpeg_audio['sample_rate']) / 144;
                    }
                } else {

                    
                    return;
                }
            }
        }

        if (@$thisfile_mpeg_audio['VBR_frames']) {
            switch ($thisfile_mpeg_audio['bitrate_mode']) {
                case 'vbr':
                case 'abr':
                    if (($thisfile_mpeg_audio['version'] == '1') && ($thisfile_mpeg_audio['layer'] == 1)) {
                        $thisfile_mpeg_audio['VBR_bitrate'] = ((@$thisfile_mpeg_audio['VBR_bytes'] / $thisfile_mpeg_audio['VBR_frames']) * 8) * ($info['audio']['sample_rate'] / 384);
                    } elseif ((($thisfile_mpeg_audio['version'] == '2') || ($thisfile_mpeg_audio['version'] == '2.5')) && ($thisfile_mpeg_audio['layer'] == 3)) {
                        $thisfile_mpeg_audio['VBR_bitrate'] = ((@$thisfile_mpeg_audio['VBR_bytes'] / $thisfile_mpeg_audio['VBR_frames']) * 8) * ($info['audio']['sample_rate'] / 576);
                    } else {
                        $thisfile_mpeg_audio['VBR_bitrate'] = ((@$thisfile_mpeg_audio['VBR_bytes'] / $thisfile_mpeg_audio['VBR_frames']) * 8) * ($info['audio']['sample_rate'] / 1152);
                    }
                    if ($thisfile_mpeg_audio['VBR_bitrate'] > 0) {
                        $info['audio']['bitrate']         = $thisfile_mpeg_audio['VBR_bitrate'];
                        $thisfile_mpeg_audio['bitrate'] = $thisfile_mpeg_audio['VBR_bitrate']; 
                    }
                    break;
            }
        }

        
        

        if ($recursive_search) {

            if (!getid3_mp3::RecursiveFrameScanning($fd, $info, $offset, $next_frame_test_offset, $scan_as_cbr)) {
                return false;
            }

        }

        return true;
    }



    public function RecursiveFrameScanning(&$fd, &$info, &$offset, &$next_frame_test_offset, $scan_as_cbr) {
        for ($i = 0; $i < getid3_mp3::VALID_CHECK_FRAMES; $i++) {
            
            if (($next_frame_test_offset + 4) >= $info['avdataend']) {
                
                return true;
            }

            $next_frame_test_array = array ('avdataend' => $info['avdataend'], 'avdataoffset' => $info['avdataoffset']);
            if ($this->decodeMPEGaudioHeader($fd, $next_frame_test_offset, $next_frame_test_array, false)) {
                if ($scan_as_cbr) {
                    
                    
                    if (!isset($next_frame_test_array['mpeg']['audio']['bitrate']) || !isset($info['mpeg']['audio']['bitrate']) || ($next_frame_test_array['mpeg']['audio']['bitrate'] != $info['mpeg']['audio']['bitrate'])) {
                        return false;
                    }
                }


                
                if (isset($next_frame_test_array['mpeg']['audio']['framelength']) && ($next_frame_test_array['mpeg']['audio']['framelength'] > 0)) {
                    $next_frame_test_offset += $next_frame_test_array['mpeg']['audio']['framelength'];
                } else {

                    
                    return;
                }

            } else {

                
                return;
            }
        }
        return true;
    }



    public function FreeFormatFrameLength($fd, $offset, &$info, $deep_scan=false) {
        fseek($fd, $offset, SEEK_SET);
        $mpeg_audio_data = fread($fd, 32768);

        $sync_pattern1 = substr($mpeg_audio_data, 0, 4);
        
        $sync_pattern2 = $sync_pattern1{0}.$sync_pattern1{1}.chr(ord($sync_pattern1{2}) | 0x02).$sync_pattern1{3};
        if ($sync_pattern2 === $sync_pattern1) {
            $sync_pattern2 = $sync_pattern1{0}.$sync_pattern1{1}.chr(ord($sync_pattern1{2}) & 0xFD).$sync_pattern1{3};
        }

        $frame_length = false;
        $frame_length1 = strpos($mpeg_audio_data, $sync_pattern1, 4);
        $frame_length2 = strpos($mpeg_audio_data, $sync_pattern2, 4);
        if ($frame_length1 > 4) {
            $frame_length = $frame_length1;
        }
        if (($frame_length2 > 4) && ($frame_length2 < $frame_length1)) {
            $frame_length = $frame_length2;
        }
        if (!$frame_length) {

            
            $frame_length1 = strpos($mpeg_audio_data, substr($sync_pattern1, 0, 3), 4);
            $frame_length2 = strpos($mpeg_audio_data, substr($sync_pattern2, 0, 3), 4);

            if ($frame_length1 > 4) {
                $frame_length = $frame_length1;
            }
            if (($frame_length2 > 4) && ($frame_length2 < $frame_length1)) {
                $frame_length = $frame_length2;
            }
            if (!$frame_length) {
                throw new getid3_exception('Cannot find next free-format synch pattern ('.getid3_lib::PrintHexBytes($sync_pattern1).' or '.getid3_lib::PrintHexBytes($sync_pattern2).') after offset '.$offset);
            } else {
                $this->getid3->warning('ModeExtension varies between first frame and other frames (known free-format issue in LAME 3.88)');
                $info['audio']['codec']   = 'LAME';
                $info['audio']['encoder'] = 'LAME3.88';
                $sync_pattern1 = substr($sync_pattern1, 0, 3);
                $sync_pattern2 = substr($sync_pattern2, 0, 3);
            }
        }

        if ($deep_scan) {

            $actual_frame_length_values = array ();
            $next_offset = $offset + $frame_length;
            while ($next_offset < ($info['avdataend'] - 6)) {
                fseek($fd, $next_offset - 1, SEEK_SET);
                $NextSyncPattern = fread($fd, 6);
                if ((substr($NextSyncPattern, 1, strlen($sync_pattern1)) == $sync_pattern1) || (substr($NextSyncPattern, 1, strlen($sync_pattern2)) == $sync_pattern2)) {
                    
                    $actual_frame_length_values[] = $frame_length;
                } elseif ((substr($NextSyncPattern, 0, strlen($sync_pattern1)) == $sync_pattern1) || (substr($NextSyncPattern, 0, strlen($sync_pattern2)) == $sync_pattern2)) {
                    
                    $actual_frame_length_values[] = ($frame_length - 1);
                    $next_offset--;
                } elseif ((substr($NextSyncPattern, 2, strlen($sync_pattern1)) == $sync_pattern1) || (substr($NextSyncPattern, 2, strlen($sync_pattern2)) == $sync_pattern2)) {
                    
                    $actual_frame_length_values[] = ($frame_length + 1);
                    $next_offset++;
                } else {
                    throw new getid3_exception('Did not find expected free-format sync pattern at offset '.$next_offset);
                }
                $next_offset += $frame_length;
            }
            if (count($actual_frame_length_values) > 0) {
                $frame_length = intval(round(array_sum($actual_frame_length_values) / count($actual_frame_length_values)));
            }
        }
        return $frame_length;
    }



    public function getOnlyMPEGaudioInfo($fd, &$info, $avdata_offset, $bit_rate_histogram=false) {

        

        fseek($fd, $avdata_offset, SEEK_SET);

        $databytes = $info['avdataend'] - $avdata_offset;
        $sync_seek_buffer_size = 128 * 1024;
        if ($databytes > 2000) { 
        	$sync_seek_buffer_size = min($databytes, $sync_seek_buffer_size);
        }
        $header = fread($fd, $sync_seek_buffer_size);
        $sync_seek_buffer_size = strlen($header);
        $synch_seek_offset = 0;

        static $mpeg_audio_version_lookup;
        static $mpeg_audio_layer_lookup;
        static $mpeg_audio_bitrate_lookup;
        if (empty($mpeg_audio_version_lookup)) {
            $mpeg_audio_version_lookup = getid3_mp3::MPEGaudioVersionarray();
            $mpeg_audio_layer_lookup   = getid3_mp3::MPEGaudioLayerarray();
            $mpeg_audio_bitrate_lookup = getid3_mp3::MPEGaudioBitratearray();

        }

        while ($synch_seek_offset < $sync_seek_buffer_size) {

            if ((($avdata_offset + $synch_seek_offset)  < $info['avdataend']) && !feof($fd)) {

                
                if ($synch_seek_offset > $sync_seek_buffer_size) {
                    throw new getid3_exception('Could not find valid MPEG audio synch within the first '.round($sync_seek_buffer_size / 1024).'kB');
                }

                if (feof($fd)) {
                    throw new getid3_exception('Could not find valid MPEG audio synch before end of file');
                }
            }

           if (($synch_seek_offset + 1) >= strlen($header)) {
                throw new getid3_exception('Could not find valid MPEG synch before end of file');
           }

           if (($header{$synch_seek_offset} == "\xFF") && ($header{($synch_seek_offset + 1)} > "\xE0")) { 

                if (!isset($first_frame_info) && !isset($info['mpeg']['audio'])) {
                    $first_frame_info = $info;
                    $first_frame_avdata_offset = $avdata_offset + $synch_seek_offset;
                    if (!getid3_mp3::decodeMPEGaudioHeader($fd, $avdata_offset + $synch_seek_offset, $first_frame_info, false)) {
                        
                        
                        unset($first_frame_info);
                    }
                }

                $dummy = $info; 
                if (getid3_mp3::decodeMPEGaudioHeader($fd, $avdata_offset + $synch_seek_offset, $dummy, true)) {
                    $info = $dummy;
                    $info['avdataoffset'] = $avdata_offset + $synch_seek_offset;

                    switch (@$info['fileformat']) {
                        case '':
                        case 'mp3':
                            $info['fileformat']          = 'mp3';
                            $info['audio']['dataformat'] = 'mp3';
                            break;
                    }
                    if (isset($first_frame_info['mpeg']['audio']['bitrate_mode']) && ($first_frame_info['mpeg']['audio']['bitrate_mode'] == 'vbr')) {
                        if (!(abs($info['audio']['bitrate'] - $first_frame_info['audio']['bitrate']) <= 1)) {
                            
                            
                            $info = $first_frame_info;
                            $info['avdataoffset']        = $first_frame_avdata_offset;
                            $info['fileformat']          = 'mp3';
                            $info['audio']['dataformat'] = 'mp3';
                            $dummy                               = $info;
                            unset($dummy['mpeg']['audio']);
                            $GarbageOffsetStart = $first_frame_avdata_offset + $first_frame_info['mpeg']['audio']['framelength'];
                            $GarbageOffsetEnd   = $avdata_offset + $synch_seek_offset;
                            if (getid3_mp3::decodeMPEGaudioHeader($fd, $GarbageOffsetEnd, $dummy, true, true)) {

                                $info = $dummy;
                                $info['avdataoffset'] = $GarbageOffsetEnd;
                                $this->getid3->warning('apparently-valid VBR header not used because could not find '.getid3_mp3::VALID_CHECK_FRAMES.' consecutive MPEG-audio frames immediately after VBR header (garbage data for '.($GarbageOffsetEnd - $GarbageOffsetStart).' bytes between '.$GarbageOffsetStart.' and '.$GarbageOffsetEnd.'), but did find valid CBR stream starting at '.$GarbageOffsetEnd);

                            } else {

                                $this->getid3->warning('using data from VBR header even though could not find '.getid3_mp3::VALID_CHECK_FRAMES.' consecutive MPEG-audio frames immediately after VBR header (garbage data for '.($GarbageOffsetEnd - $GarbageOffsetStart).' bytes between '.$GarbageOffsetStart.' and '.$GarbageOffsetEnd.')');

                            }
                        }
                    }
                    if (isset($info['mpeg']['audio']['bitrate_mode']) && ($info['mpeg']['audio']['bitrate_mode'] == 'vbr') && !isset($info['mpeg']['audio']['VBR_method'])) {
                        
                        $bit_rate_histogram = true;
                    }

                    if ($bit_rate_histogram) {

                        $info['mpeg']['audio']['stereo_distribution']  = array ('stereo'=>0, 'joint stereo'=>0, 'dual channel'=>0, 'mono'=>0);
                        $info['mpeg']['audio']['version_distribution'] = array ('1'=>0, '2'=>0, '2.5'=>0);

                        if ($info['mpeg']['audio']['version'] == '1') {
                            if ($info['mpeg']['audio']['layer'] == 3) {
                                $info['mpeg']['audio']['bitrate_distribution'] = array ('free'=>0, 32000=>0, 40000=>0, 48000=>0, 56000=>0, 64000=>0, 80000=>0, 96000=>0, 112000=>0, 128000=>0, 160000=>0, 192000=>0, 224000=>0, 256000=>0, 320000=>0);
                            } elseif ($info['mpeg']['audio']['layer'] == 2) {
                                $info['mpeg']['audio']['bitrate_distribution'] = array ('free'=>0, 32000=>0, 48000=>0, 56000=>0, 64000=>0, 80000=>0, 96000=>0, 112000=>0, 128000=>0, 160000=>0, 192000=>0, 224000=>0, 256000=>0, 320000=>0, 384000=>0);
                            } elseif ($info['mpeg']['audio']['layer'] == 1) {
                                $info['mpeg']['audio']['bitrate_distribution'] = array ('free'=>0, 32000=>0, 64000=>0, 96000=>0, 128000=>0, 160000=>0, 192000=>0, 224000=>0, 256000=>0, 288000=>0, 320000=>0, 352000=>0, 384000=>0, 416000=>0, 448000=>0);
                            }
                        } elseif ($info['mpeg']['audio']['layer'] == 1) {
                            $info['mpeg']['audio']['bitrate_distribution'] = array ('free'=>0, 32000=>0, 48000=>0, 56000=>0, 64000=>0, 80000=>0, 96000=>0, 112000=>0, 128000=>0, 144000=>0, 160000=>0, 176000=>0, 192000=>0, 224000=>0, 256000=>0);
                        } else {
                            $info['mpeg']['audio']['bitrate_distribution'] = array ('free'=>0, 8000=>0, 16000=>0, 24000=>0, 32000=>0, 40000=>0, 48000=>0, 56000=>0, 64000=>0, 80000=>0, 96000=>0, 112000=>0, 128000=>0, 144000=>0, 160000=>0);
                        }

                        $dummy = array ('avdataend' => $info['avdataend'], 'avdataoffset' => $info['avdataoffset']);
                        $synch_start_offset = $info['avdataoffset'];

                        $fast_mode = false;
                        $synch_errors_found = 0;
                        while ($this->decodeMPEGaudioHeader($fd, $synch_start_offset, $dummy, false, false, $fast_mode)) {
                            $fast_mode = true;
                            $thisframebitrate = $mpeg_audio_bitrate_lookup[$mpeg_audio_version_lookup[$dummy['mpeg']['audio']['raw']['version']]][$mpeg_audio_layer_lookup[$dummy['mpeg']['audio']['raw']['layer']]][$dummy['mpeg']['audio']['raw']['bitrate']];

                            if (empty($dummy['mpeg']['audio']['framelength'])) {
                                $synch_errors_found++;
                            }
                            else {
                                @$info['mpeg']['audio']['bitrate_distribution'][$thisframebitrate]++;
                                @$info['mpeg']['audio']['stereo_distribution'][$dummy['mpeg']['audio']['channelmode']]++;
                                @$info['mpeg']['audio']['version_distribution'][$dummy['mpeg']['audio']['version']]++;

                                $synch_start_offset += $dummy['mpeg']['audio']['framelength'];
                            }
                        }
                        if ($synch_errors_found > 0) {
                            $this->getid3->warning('Found '.$synch_errors_found.' synch errors in histogram analysis');
                        }

                        $bit_total     = 0;
                        $frame_counter = 0;
                        foreach ($info['mpeg']['audio']['bitrate_distribution'] as $bit_rate_value => $bit_rate_count) {
                            $frame_counter += $bit_rate_count;
                            if ($bit_rate_value != 'free') {
                                $bit_total += ($bit_rate_value * $bit_rate_count);
                            }
                        }
                        if ($frame_counter == 0) {
                            throw new getid3_exception('Corrupt MP3 file: framecounter == zero');
                        }
                        $info['mpeg']['audio']['frame_count'] = $frame_counter;
                        $info['mpeg']['audio']['bitrate']     = ($bit_total / $frame_counter);

                        $info['audio']['bitrate'] = $info['mpeg']['audio']['bitrate'];


                        
                        $distinct_bit_rates = 0;
                        foreach ($info['mpeg']['audio']['bitrate_distribution'] as $bit_rate_value => $bit_rate_count) {
                            if ($bit_rate_count > 0) {
                                $distinct_bit_rates++;
                            }
                        }
                        if ($distinct_bit_rates > 1) {
                            $info['mpeg']['audio']['bitrate_mode'] = 'vbr';
                        } else {
                            $info['mpeg']['audio']['bitrate_mode'] = 'cbr';
                        }
                        $info['audio']['bitrate_mode'] = $info['mpeg']['audio']['bitrate_mode'];

                    }

                    break; 
                }
            }

            $synch_seek_offset++;
            if (($avdata_offset + $synch_seek_offset) >= $info['avdataend']) {
                

                if (empty($info['mpeg']['audio'])) {

                    throw new getid3_exception('could not find valid MPEG synch before end of file');
                }
                break;
            }

        }

        $info['audio']['channels']        = $info['mpeg']['audio']['channels'];
        $info['audio']['channelmode']     = $info['mpeg']['audio']['channelmode'];
        $info['audio']['sample_rate']     = $info['mpeg']['audio']['sample_rate'];
        return true;
    }



    public static function MPEGaudioVersionarray() {

        static $array = array ('2.5', false, '2', '1');
        return $array;
    }



    public static function MPEGaudioLayerarray() {

        static $array = array (false, 3, 2, 1);
        return $array;
    }



    public static function MPEGaudioBitratearray() {

        static $array;
        if (empty($array)) {
            $array = array (
                '1'  =>  array (1 => array ('free', 32000, 64000, 96000, 128000, 160000, 192000, 224000, 256000, 288000, 320000, 352000, 384000, 416000, 448000),
                                2 => array ('free', 32000, 48000, 56000,  64000,  80000,  96000, 112000, 128000, 160000, 192000, 224000, 256000, 320000, 384000),
                                3 => array ('free', 32000, 40000, 48000,  56000,  64000,  80000,  96000, 112000, 128000, 160000, 192000, 224000, 256000, 320000)
                               ),

                '2'  =>  array (1 => array ('free', 32000, 48000, 56000,  64000,  80000,  96000, 112000, 128000, 144000, 160000, 176000, 192000, 224000, 256000),
                                2 => array ('free',  8000, 16000, 24000,  32000,  40000,  48000,  56000,  64000,  80000,  96000, 112000, 128000, 144000, 160000),
                               )
            );
            $array['2'][3] = $array['2'][2];
            $array['2.5']  = $array['2'];
        }
        return $array;
    }



    public static function MPEGaudioFrequencyarray() {

        static $array = array (
                '1'   => array (44100, 48000, 32000),
                '2'   => array (22050, 24000, 16000),
                '2.5' => array (11025, 12000,  8000)
        );
        return $array;
    }



    public static function MPEGaudioChannelModearray() {

        static $array = array ('stereo', 'joint stereo', 'dual channel', 'mono');
        return $array;
    }



    public static function MPEGaudioModeExtensionarray() {

        static $array = array (
                1 => array ('4-31', '8-31', '12-31', '16-31'),
                2 => array ('4-31', '8-31', '12-31', '16-31'),
                3 => array ('', 'IS', 'MS', 'IS+MS')
        );
        return $array;
    }



    public static function MPEGaudioEmphasisarray() {

        static $array = array ('none', '50/15ms', false, 'CCIT J.17');
        return $array;
    }



    public static function MPEGaudioHeaderBytesValid($head4, $allow_bitrate_15=false) {

        return getid3_mp3::MPEGaudioHeaderValid(getid3_mp3::MPEGaudioHeaderDecode($head4), false, $allow_bitrate_15);
    }



    public static function MPEGaudioHeaderValid($raw_array, $echo_errors=false, $allow_bitrate_15=false) {

        if (($raw_array['synch'] & 0x0FFE) != 0x0FFE) {
            return false;
        }

        static $mpeg_audio_version_lookup;
        static $mpeg_audio_layer_lookup;
        static $mpeg_audio_bitrate_lookup;
        static $mpeg_audio_frequency_lookup;
        static $mpeg_audio_channel_mode_lookup;
        static $mpeg_audio_mode_extension_lookup;
        static $mpeg_audio_emphasis_lookup;
        if (empty($mpeg_audio_version_lookup)) {
            $mpeg_audio_version_lookup        = getid3_mp3::MPEGaudioVersionarray();
            $mpeg_audio_layer_lookup          = getid3_mp3::MPEGaudioLayerarray();
            $mpeg_audio_bitrate_lookup        = getid3_mp3::MPEGaudioBitratearray();
            $mpeg_audio_frequency_lookup      = getid3_mp3::MPEGaudioFrequencyarray();
            $mpeg_audio_channel_mode_lookup   = getid3_mp3::MPEGaudioChannelModearray();
            $mpeg_audio_mode_extension_lookup = getid3_mp3::MPEGaudioModeExtensionarray();
            $mpeg_audio_emphasis_lookup       = getid3_mp3::MPEGaudioEmphasisarray();
        }

        if (isset($mpeg_audio_version_lookup[$raw_array['version']])) {
            $decodedVersion = $mpeg_audio_version_lookup[$raw_array['version']];
        } else {
            echo ($echo_errors ? "\n".'invalid Version ('.$raw_array['version'].')' : '');
            return false;
        }
        if (isset($mpeg_audio_layer_lookup[$raw_array['layer']])) {
            $decodedLayer = $mpeg_audio_layer_lookup[$raw_array['layer']];
        } else {
            echo ($echo_errors ? "\n".'invalid Layer ('.$raw_array['layer'].')' : '');
            return false;
        }
        if (!isset($mpeg_audio_bitrate_lookup[$decodedVersion][$decodedLayer][$raw_array['bitrate']])) {
            echo ($echo_errors ? "\n".'invalid Bitrate ('.$raw_array['bitrate'].')' : '');
            if ($raw_array['bitrate'] == 15) {
                
                
                if (!$allow_bitrate_15) {
                    return false;
                }
            } else {
                return false;
            }
        }
        if (!isset($mpeg_audio_frequency_lookup[$decodedVersion][$raw_array['sample_rate']])) {
            echo ($echo_errors ? "\n".'invalid Frequency ('.$raw_array['sample_rate'].')' : '');
            return false;
        }
        if (!isset($mpeg_audio_channel_mode_lookup[$raw_array['channelmode']])) {
            echo ($echo_errors ? "\n".'invalid ChannelMode ('.$raw_array['channelmode'].')' : '');
            return false;
        }
        if (!isset($mpeg_audio_mode_extension_lookup[$decodedLayer][$raw_array['modeextension']])) {
            echo ($echo_errors ? "\n".'invalid Mode Extension ('.$raw_array['modeextension'].')' : '');
            return false;
        }
        if (!isset($mpeg_audio_emphasis_lookup[$raw_array['emphasis']])) {
            echo ($echo_errors ? "\n".'invalid Emphasis ('.$raw_array['emphasis'].')' : '');
            return false;
        }
        
        
        
        
        
        

        return true;
    }



    public static function MPEGaudioHeaderDecode($header_four_bytes) {
        
        
        
        
        
        
        
        
        
        
        
        
        
        

        if (strlen($header_four_bytes) != 4) {
            return false;
        }

        $mpeg_raw_header['synch']         = (getid3_lib::BigEndian2Int(substr($header_four_bytes, 0, 2)) & 0xFFE0) >> 4;
        $mpeg_raw_header['version']       = (ord($header_four_bytes{1}) & 0x18) >> 3; 
        $mpeg_raw_header['layer']         = (ord($header_four_bytes{1}) & 0x06) >> 1; 
        $mpeg_raw_header['protection']    = (ord($header_four_bytes{1}) & 0x01);      
        $mpeg_raw_header['bitrate']       = (ord($header_four_bytes{2}) & 0xF0) >> 4; 
        $mpeg_raw_header['sample_rate']   = (ord($header_four_bytes{2}) & 0x0C) >> 2; 
        $mpeg_raw_header['padding']       = (ord($header_four_bytes{2}) & 0x02) >> 1; 
        $mpeg_raw_header['private']       = (ord($header_four_bytes{2}) & 0x01);      
        $mpeg_raw_header['channelmode']   = (ord($header_four_bytes{3}) & 0xC0) >> 6; 
        $mpeg_raw_header['modeextension'] = (ord($header_four_bytes{3}) & 0x30) >> 4; 
        $mpeg_raw_header['copyright']     = (ord($header_four_bytes{3}) & 0x08) >> 3; 
        $mpeg_raw_header['original']      = (ord($header_four_bytes{3}) & 0x04) >> 2; 
        $mpeg_raw_header['emphasis']      = (ord($header_four_bytes{3}) & 0x03);      

        return $mpeg_raw_header;
    }



    public static function MPEGaudioFrameLength(&$bit_rate, &$version, &$layer, $padding, &$sample_rate) {

        if (!isset($cache[$bit_rate][$version][$layer][$padding][$sample_rate])) {
            $cache[$bit_rate][$version][$layer][$padding][$sample_rate] = false;
            if ($bit_rate != 'free') {

                if ($version == '1') {

                    if ($layer == '1') {

                        
                        $frame_length_coefficient = 48;
                        $slot_length = 4;

                    } else { 

                        
                        $frame_length_coefficient = 144;
                        $slot_length = 1;

                    }

                } else { 

                    if ($layer == '1') {

                        
                        $frame_length_coefficient = 24;
                        $slot_length = 4;

                    } elseif ($layer == '2') {

                        
                        $frame_length_coefficient = 144;
                        $slot_length = 1;

                    } else { 

                        
                        $frame_length_coefficient = 72;
                        $slot_length = 1;

                    }

                }

                
                if ($sample_rate > 0) {
                    $new_frame_length  = ($frame_length_coefficient * $bit_rate) / $sample_rate;
                    $new_frame_length  = floor($new_frame_length / $slot_length) * $slot_length; 
                    if ($padding) {
                        $new_frame_length += $slot_length;
                    }
                    $cache[$bit_rate][$version][$layer][$padding][$sample_rate] = (int) $new_frame_length;
                }
            }
        }
        return $cache[$bit_rate][$version][$layer][$padding][$sample_rate];
    }



    public static function ClosestStandardMP3Bitrate($bit_rate) {
        static $standard_bit_rates = array (320000, 256000, 224000, 192000, 160000, 128000, 112000, 96000, 80000, 64000, 56000, 48000, 40000, 32000, 24000, 16000, 8000);
        static $bit_rate_table = array (0=>'-');
        $round_bit_rate = intval(round($bit_rate, -3));
		if (!isset($bit_rate_table[$round_bit_rate])) {
			if ($round_bit_rate > max($standard_bit_rates)) {
				$bit_rate_table[$round_bit_rate] = round($bit_rate, 2 - strlen($bit_rate));
			} else {
				$bit_rate_table[$round_bit_rate] = max($standard_bit_rates);
				foreach ($standard_bit_rates as $standard_bit_rate) {
					if ($round_bit_rate >= $standard_bit_rate + (($bit_rate_table[$round_bit_rate] - $standard_bit_rate) / 2)) {
						break;
					}
					$bit_rate_table[$round_bit_rate] = $standard_bit_rate;
				}
			}
		}
		return $bit_rate_table[$round_bit_rate];
    }



    public static function XingVBRidOffset($version, $channel_mode) {

        static $lookup = array (
                '1'   => array ('mono'          => 0x15, 
                                'stereo'        => 0x24, 
                                'joint stereo'  => 0x24,
                                'dual channel'  => 0x24
                               ),

                '2'   => array ('mono'          => 0x0D, 
                                'stereo'        => 0x15, 
                                'joint stereo'  => 0x15,
                                'dual channel'  => 0x15
                               ),

                '2.5' => array ('mono'          => 0x15,
                                'stereo'        => 0x15,
                                'joint stereo'  => 0x15,
                                'dual channel'  => 0x15
                               )
        );

        return $lookup[$version][$channel_mode];
    }



    public static function LAMEvbrMethodLookup($vbr_method_id) {

        static $lookup = array (
            0x00 => 'unknown',
            0x01 => 'cbr',
            0x02 => 'abr',
            0x03 => 'vbr-old / vbr-rh',
            0x04 => 'vbr-new / vbr-mtrh',
            0x05 => 'vbr-mt',
            0x06 => 'Full VBR Method 4',
            0x08 => 'constant bitrate 2 pass',
            0x09 => 'abr 2 pass',
            0x0F => 'reserved'
        );
        return (isset($lookup[$vbr_method_id]) ? $lookup[$vbr_method_id] : '');
    }



    public static function LAMEmiscStereoModeLookup($stereo_mode_id) {

        static $lookup = array (
            0 => 'mono',
            1 => 'stereo',
            2 => 'dual mono',
            3 => 'joint stereo',
            4 => 'forced stereo',
            5 => 'auto',
            6 => 'intensity stereo',
            7 => 'other'
        );
        return (isset($lookup[$stereo_mode_id]) ? $lookup[$stereo_mode_id] : '');
    }



    public static function LAMEmiscSourceSampleFrequencyLookup($source_sample_frequency_id) {

        static $lookup = array (
            0 => '<= 32 kHz',
            1 => '44.1 kHz',
            2 => '48 kHz',
            3 => '> 48kHz'
        );
        return (isset($lookup[$source_sample_frequency_id]) ? $lookup[$source_sample_frequency_id] : '');
    }



    public static function LAMEsurroundInfoLookup($surround_info_id) {

        static $lookup = array (
            0 => 'no surround info',
            1 => 'DPL encoding',
            2 => 'DPL2 encoding',
            3 => 'Ambisonic encoding'
        );
        return (isset($lookup[$surround_info_id]) ? $lookup[$surround_info_id] : 'reserved');
    }



    public static function LAMEpresetUsedLookup($lame_tag) {

        if ($lame_tag['preset_used_id'] == 0) {
            
            
            return '';
        }

        $lame_preset_used_lookup = array ();

        for ($i = 8; $i <= 320; $i++) {
            switch ($lame_tag['vbr_method']) {
                case 'cbr':
                    $lame_preset_used_lookup[$i] = '--alt-preset '.$lame_tag['vbr_method'].' '.$i;
                    break;
                case 'abr':
                default: 
                    $lame_preset_used_lookup[$i] = '--alt-preset '.$i;
                    break;
            }
        }

        

        
        $lame_preset_used_lookup[1000] = '--r3mix';
        $lame_preset_used_lookup[1001] = '--alt-preset standard';
        $lame_preset_used_lookup[1002] = '--alt-preset extreme';
        $lame_preset_used_lookup[1003] = '--alt-preset insane';
        $lame_preset_used_lookup[1004] = '--alt-preset fast standard';
        $lame_preset_used_lookup[1005] = '--alt-preset fast extreme';
        $lame_preset_used_lookup[1006] = '--alt-preset medium';
        $lame_preset_used_lookup[1007] = '--alt-preset fast medium';

        
        $lame_preset_used_lookup[1010] = '--preset portable';                                                            
        $lame_preset_used_lookup[1015] = '--preset radio';                                                               

        $lame_preset_used_lookup[320]  = '--preset insane';                                                              
        $lame_preset_used_lookup[410]  = '-V9';
        $lame_preset_used_lookup[420]  = '-V8';
        $lame_preset_used_lookup[430]  = '--preset radio';                                                               
        $lame_preset_used_lookup[440]  = '-V6';
        $lame_preset_used_lookup[450]  = '--preset '.(($lame_tag['raw']['vbr_method'] == 4) ? 'fast ' : '').'portable';  
        $lame_preset_used_lookup[460]  = '--preset '.(($lame_tag['raw']['vbr_method'] == 4) ? 'fast ' : '').'medium';    
        $lame_preset_used_lookup[470]  = '--r3mix';                                                                      
        $lame_preset_used_lookup[480]  = '--preset '.(($lame_tag['raw']['vbr_method'] == 4) ? 'fast ' : '').'standard';  
        $lame_preset_used_lookup[490]  = '-V1';
        $lame_preset_used_lookup[500]  = '--preset '.(($lame_tag['raw']['vbr_method'] == 4) ? 'fast ' : '').'extreme';   

        return (isset($lame_preset_used_lookup[$lame_tag['preset_used_id']]) ? $lame_preset_used_lookup[$lame_tag['preset_used_id']] : 'new/unknown preset: '.$lame_tag['preset_used_id'].' - report to info@getid3.org');
    }


}


























class getid3
{
    

    
    public $encoding                 = 'ISO-8859-1';      
    public $encoding_id3v1           = 'ISO-8859-1';      
    public $encoding_id3v2           = 'ISO-8859-1';      

    
    public $option_tag_id3v1         = true;              
    public $option_tag_id3v2         = true;              
    public $option_tag_lyrics3       = true;              
    public $option_tag_apetag        = true;              

    
    public $option_analyze           = true;              
    public $option_accurate_results  = true;              
    public $option_tags_process      = true;              
    public $option_tags_images       = false;             
    public $option_extra_info        = true;              
    public $option_max_2gb_check     = false;             

    
    public $option_md5_data          = false;             
    public $option_md5_data_source   = false;             
    public $option_sha1_data         = false;             

    
    public $filename;                                     
    public $fp;                                           
    public $info;                                         

    
    protected $include_path;                              
    protected $warnings = array ();
    protected $iconv_present;

    
    const VERSION           = '2.0.0b6-20101125';
    const FREAD_BUFFER_SIZE = 16384;                      
    const ICONV_TEST_STRING = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~ÄÅÇÉÑÖÜáàâäãåçéèêëíìîïñóòôöõúùûü °¢£§•¶ß®©™´¨≠ÆØ∞±≤≥¥µ∂∑∏π∫ªºΩæø¿¡¬√ƒ≈∆«»… ÀÃÕŒœ–—“”‘’÷◊ÿŸ⁄€‹›ﬁﬂ‡·‚„‰ÂÊÁËÈÍÎÏÌÓÔÒÚÛÙıˆ˜¯˘˙˚¸˝˛ˇ';



    
    public function __construct() {

        
        static $include_path;
        static $iconv_present;


        static $initialized;
        if ($initialized) {

            
            $this->include_path  = $include_path;
            $this->iconv_present = $iconv_present;

            
            return;
        }

        
        $this->include_path = $include_path = dirname(__FILE__) . '/getid3/';

        
        if (function_exists('iconv') && @iconv('UTF-16LE', 'ISO-8859-1', @iconv('ISO-8859-1', 'UTF-16LE', getid3::ICONV_TEST_STRING)) == getid3::ICONV_TEST_STRING) {
            $this->iconv_present = $iconv_present = true;
        }

        
        else {
            $this->include_module('lib.iconv_replacement');
            $this->iconv_present = $iconv_present = false;
        }


        
        if (get_magic_quotes_runtime()) {
            throw new getid3_exception('magic_quotes_runtime must be disabled before running getID3(). Surround getid3 block by set_magic_quotes_runtime(0) and set_magic_quotes_runtime(1).');
        }


        
        $memory_limit = ini_get('memory_limit');
        if (preg_match('#([0-9]+)M#i', $memory_limit, $matches)) {
            
            $memory_limit = $matches[1] * 1048576;
		} elseif (preg_match('#([0-9]+)G#i', $memory_limit, $matches)) {  
			
			$memory_limit = $matches[1] * 1073741824;
        }
        if ($memory_limit <= 0) {
            
        } elseif ($memory_limit <= 4194304) {
            $this->warning('[SERIOUS] PHP has less than 4 Mb available memory and will very likely run out. Increase memory_limit in php.ini.');
        } elseif ($memory_limit <= 12582912) {
            $this->warning('PHP has less than 12 Mb available memory and might run out if all modules are loaded. Increase memory_limit in php.ini if needed.');
        }


        
        if (preg_match('#(1|ON)#i', ini_get('safe_mode'))) {
            $this->warning('Safe mode is on, shorten support disabled, md5data/sha1data for ogg vorbis disabled, ogg vorbis/flac tag writing disabled.');
        }

		if (intval(ini_get('mbstring.func_overload')) > 0) {
		    $this->warning('WARNING: php.ini contains "mbstring.func_overload = '.ini_get('mbstring.func_overload').'", this may break things.');
		}

		
		if (function_exists('date_default_timezone_set')) {
			date_default_timezone_set('America/New_York');
		} else {
			ini_set('date.timezone', 'America/New_York');
		}

        $initialized = true;
    }



    
    public function Analyze($filename) {

        
        $this->filename = $filename;
        $this->warnings = array ();

        
        $this->info = array ();
        $this->info['GETID3_VERSION'] = getid3::VERSION;

        
        if (preg_match('/^(ht|f)tp:\/\//', $filename)) {
            throw new getid3_exception('Remote files are not supported - please copy the file locally first.');
        }

        
        if (!$this->fp = @fopen($filename, 'rb')) {
            throw new getid3_exception('Could not open file "'.$filename.'"');
        }

        
        $this->info['filesize']     = filesize($filename);
        $this->info['avdataoffset'] = 0;
        $this->info['avdataend']    = $this->info['filesize'];

        
        if ($this->option_max_2gb_check) {
            
            
            
            fseek($this->fp, 0, SEEK_END);
            if ((($this->info['filesize'] != 0) && (ftell($this->fp) == 0)) ||
                ($this->info['filesize'] < 0) ||
                (ftell($this->fp) < 0)) {
                    unset($this->info['filesize']);
                    fclose($this->fp);
                    throw new getid3_exception('File is most likely larger than 2GB and is not supported by PHP.');
            }
        }


        
        if (!$this->option_tag_id3v2) {

            fseek($this->fp, 0, SEEK_SET);
            $header = fread($this->fp, 10);
            if (substr($header, 0, 3) == 'ID3'  &&  strlen($header) == 10) {
                $this->info['id3v2']['header']        = true;
                $this->info['id3v2']['majorversion']  = ord($header{3});
                $this->info['id3v2']['minorversion']  = ord($header{4});
                $this->info['avdataoffset']          += getid3_lib::BigEndian2Int(substr($header, 6, 4), 1) + 10; 
            }
        }


        
        foreach (array ("id3v2", "id3v1", "apetag", "lyrics3") as $tag_name) {

            $option_tag = 'option_tag_' . $tag_name;
            if ($this->$option_tag) {
                $this->include_module('tag.'.$tag_name);
                try {
                    $tag_class = 'getid3_' . $tag_name;
                    $tag = new $tag_class($this);
                    $tag->Analyze();
                }
                catch (getid3_exception $e) {
                    throw $e;
                }
            }
        }



        

        
        fseek($this->fp, $this->info['avdataoffset'], SEEK_SET);
        $filedata = fread($this->fp, 32774);

        
        $file_format_array = getid3::GetFileFormatArray();

        
        foreach ($file_format_array as $name => $info) {

            if (@$info['pattern'] && preg_match('/'.$info['pattern'].'/s', $filedata)) {                         

                
                if (!@$info['module'] || !@$info['group']) {
                    fclose($this->fp);
                    $this->info['fileformat'] = $name;
                    $this->info['mime_type']  = $info['mime_type'];
                    $this->warning('Format only detected. Parsing not available yet.');
                    $this->info['warning'] = $this->warnings;
                    return $this->info;
                }

                $determined_format = $info;  
                continue;
            }
        }

        
        if (!@$determined_format) {

            if (preg_match('/\.mp[123a]$/i', $filename)) {

	            
	            
                $determined_format = $file_format_array['mp3'];

			} elseif (preg_match('/\.cue$/i', $filename) && preg_match('#FILE "[^"]+" (BINARY|MOTOROLA|AIFF|WAVE|MP3)#', $filedata)) {

				
				
				
                $determined_format = $file_format_array['cue'];

            } else {

                fclose($this->fp);
                throw new getid3_exception('Unable to determine file format');

            }
        }

        
        unset($file_format_array);

        
        if (@$determined_format['fail_id3'] && (@$this->info['id3v1'] || @$this->info['id3v2'])) {
            if ($determined_format['fail_id3'] === 'ERROR') {
                fclose($this->fp);
                throw new getid3_exception('ID3 tags not allowed on this file type.');
            }
            elseif ($determined_format['fail_id3'] === 'WARNING') {
                @$this->info['id3v1'] and $this->warning('ID3v1 tags not allowed on this file type.');
                @$this->info['id3v2'] and $this->warning('ID3v2 tags not allowed on this file type.');
            }
        }

        
        if (@$determined_format['fail_ape'] && @$this->info['tags']['ape']) {
            if ($determined_format['fail_ape'] === 'ERROR') {
                fclose($this->fp);
                throw new getid3_exception('APE tags not allowed on this file type.');
            } elseif ($determined_format['fail_ape'] === 'WARNING') {
                $this->warning('APE tags not allowed on this file type.');
            }
        }


        
        $this->info['mime_type'] = $determined_format['mime_type'];

        
        $determined_format['include'] = 'module.'.$determined_format['group'].'.'.$determined_format['module'].'.php';

        global $config;
        if (!in_array(pathinfo($filename, PATHINFO_EXTENSION), $config['formats'])) {
            fclose($this->fp);
            throw new getid3_exception('Format not supported, module "'.$determined_format['include'].'" was removed.');
        }

        
        $this->include_module($determined_format['group'].'.'.$determined_format['module']);

        
        $class_name = 'getid3_'.$determined_format['module'];
        if (!class_exists($class_name)) {
            throw new getid3_exception('Format not supported, module "'.$determined_format['include'].'" is corrupt.');
        }
        $class = new $class_name($this);

        try {
             $this->option_analyze and $class->Analyze();
            }
        catch (getid3_exception $e) {
            throw $e;
        }
        catch (Exception $e) {
            throw new getid3_exception('Corrupt file.');
        }

        
        fclose($this->fp);

        
        if ($this->option_tags_process) {
            $this->HandleAllTags();
        }


        
        if ($this->option_extra_info) {
			$this->ChannelsBitratePlaytimeCalculations();
			$this->CalculateCompressionRatioVideo();
			$this->CalculateCompressionRatioAudio();
			$this->CalculateReplayGain();
			$this->ProcessAudioStreams();
        }


        
        if ($this->option_md5_data || $this->option_sha1_data) {

            
            $this->include_module('lib.data_hash');

            if ($this->option_sha1_data) {
                new getid3_lib_data_hash($this, 'sha1');
            }

            if ($this->option_md5_data) {

                
                if (!$this->option_md5_data_source || !@$this->info['md5_data_source']) {
                    new getid3_lib_data_hash($this, 'md5');
                }

                
                elseif ($this->option_md5_data_source && @$this->info['md5_data_source']) {
                    $this->info['md5_data'] = $this->info['md5_data_source'];
                }
            }
        }

        
        if ($this->warnings) {
            $this->info['warning'] = $this->warnings;
        }

        
        return $this->info;
    }



    
    public function warnings() {

        return $this->warnings;
    }



    
    public function warning($message) {

        if (is_array($message)) {
            $this->warnings = array_merge($this->warnings, $message);
        }
        else {
            $this->warnings[] = $message;
        }
    }



    
    public function __clone() {

        $this->warnings = array ();

        
        $temp = $this->info;
        unset($this->info);
        $this->info = $temp;
    }



    
    public function iconv($in_charset, $out_charset, $string, $drop01 = false) {

        if ($drop01 && ($string === "\x00" || $string === "\x01")) {
            return '';
        }


        if (!$this->iconv_present) {
            return getid3_iconv_replacement::iconv($in_charset, $out_charset, $string);
        }


        
        if ($result = @iconv($in_charset, $out_charset.'//TRANSLIT', $string)) {

            if ($out_charset == 'ISO-8859-1') {
                return rtrim($result, "\x00");
            }
            return $result;
        }

        $this->warning('iconv() was unable to convert the string: "' . $string . '" from ' . $in_charset . ' to ' . $out_charset);
        return $string;
    }



    public function include_module($name) {

    }



    public function include_module_optional($name) {

        if (!file_exists($this->include_path.'module.'.$name.'.php')) {
            return;
        }

        return true;
    }


    
    public static function GetFileFormatArray() {

        static $format_info = array (

                

                
                'ac3'  => array (
                            'pattern'   => '^\x0B\x77',
                            'group'     => 'audio',
                            'module'    => 'ac3',
                            'mime_type' => 'audio/ac3',
                          ),

                
                'aa'   => array (
                            'pattern'   => '^.{4}\x57\x90\x75\x36',
                            'group'     => 'audio',
                            'module'    => 'aa',
                            'mime_type' => 'audio/audible',
                          ),

                
                'adif' => array (
                            'pattern'   => '^ADIF',
                            'group'     => 'audio',
                            'module'    => 'aac_adif',
                            'mime_type' => 'application/octet-stream',
                            'fail_ape'  => 'WARNING',
                          ),


                
                'adts' => array (
                            'pattern'   => '^\xFF[\xF0-\xF1\xF8-\xF9]',
                            'group'     => 'audio',
                            'module'    => 'aac_adts',
                            'mime_type' => 'application/octet-stream',
                            'fail_ape'  => 'WARNING',
                          ),


                
                'au'   => array (
                            'pattern'   => '^\.snd',
                            'group'     => 'audio',
                            'module'    => 'au',
                            'mime_type' => 'audio/basic',
                          ),

                
                'avr'  => array (
                            'pattern'   => '^2BIT',
                            'group'     => 'audio',
                            'module'    => 'avr',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'bonk' => array (
                            'pattern'   => '^\x00(BONK|INFO|META| ID3)',
                            'group'     => 'audio',
                            'module'    => 'bonk',
                            'mime_type' => 'audio/xmms-bonk',
                          ),

				
				'dss'  => array(
							'pattern'   => '^[\x02]dss',
							'group'     => 'audio',
							'module'    => 'dss',
							'mime_type' => 'application/octet-stream',
						),

                
				'dts'  => array(
							'pattern'   => '^\x7F\xFE\x80\x01',
							'group'     => 'audio',
							'module'    => 'dts',
							'mime_type' => 'audio/dts',
						),

                
                'flac' => array (
                            'pattern'   => '^fLaC',
                            'group'     => 'audio',
                            'module'    => 'xiph',
                            'mime_type' => 'audio/x-flac',
                          ),

                
                'la'   => array (
                            'pattern'   => '^LA0[2-4]',
                            'group'     => 'audio',
                            'module'    => 'la',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'lpac' => array (
                            'pattern'   => '^LPAC',
                            'group'     => 'audio',
                            'module'    => 'lpac',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'midi' => array (
                            'pattern'   => '^MThd',
                            'group'     => 'audio',
                            'module'    => 'midi',
                            'mime_type' => 'audio/midi',
                          ),

                
                'mac'  => array (
                            'pattern'   => '^MAC ',
                            'group'     => 'audio',
                            'module'    => 'monkey',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'mod'  => array (
                            'pattern'   => '^.{1080}(M.K.|[5-9]CHN|[1-3][0-9]CH)',
                            'mime_type' => 'audio/mod',
                          ),

                
                'it'   => array (
                            'pattern'   => '^IMPM',
                            'mime_type' => 'audio/it',
                          ),

                
                'xm'   => array (
                            'pattern'   => '^Extended Module',
                            'mime_type' => 'audio/xm',
                          ),

                
                's3m'  => array (
                            'pattern'   => '^.{44}SCRM',
                            'mime_type' => 'audio/s3m',
                          ),

                
                'mpc8' => array (
                            'pattern'   => '^(MPCK)',
                            'group'     => 'audio',
                            'module'    => 'mpc8',
                            'mime_type' => 'audio/x-musepack',
                          ),

                
                'mpc7' => array (
                            'pattern'   => '^(MP\+)',
                            'group'     => 'audio',
                            'module'    => 'mpc7',
                            'mime_type' => 'audio/x-musepack',
                          ),

                
                'mpc_old' => array (
                            'pattern'   => '^([\x00\x01\x10\x11\x40\x41\x50\x51\x80\x81\x90\x91\xC0\xC1\xD0\xD1][\x20-37][\x00\x20\x40\x60\x80\xA0\xC0\xE0])',
                            'group'     => 'audio',
                            'module'    => 'mpc_old',
                            'mime_type' => 'application/octet-stream',
                          ),


                
                'mp3'  => array (
                            'pattern'   => '^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\x0B\x10-\x1B\x20-\x2B\x30-\x3B\x40-\x4B\x50-\x5B\x60-\x6B\x70-\x7B\x80-\x8B\x90-\x9B\xA0-\xAB\xB0-\xBB\xC0-\xCB\xD0-\xDB\xE0-\xEB\xF0-\xFB]',
                            'group'     => 'audio',
                            'module'    => 'mp3',
                            'mime_type' => 'audio/mpeg',
                          ),

                
                'ofr'  => array (
                            'pattern'   => '^(\*RIFF|OFR)',
                            'group'     => 'audio',
                            'module'    => 'optimfrog',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'rkau' => array (
                            'pattern'   => '^RKA',
                            'group'     => 'audio',
                            'module'    => 'rkau',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'shn'  => array (
                            'pattern'   => '^ajkg',
                            'group'     => 'audio',
                            'module'    => 'shorten',
                            'mime_type' => 'audio/xmms-shn',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'tta'  => array (
                            'pattern'   => '^TTA',  
                            'group'     => 'audio',
                            'module'    => 'tta',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'voc'  => array (
                            'pattern'   => '^Creative Voice File',
                            'group'     => 'audio',
                            'module'    => 'voc',
                            'mime_type' => 'audio/voc',
                          ),

                
                'vqf'  => array (
                            'pattern'   => '^TWIN',
                            'group'     => 'audio',
                            'module'    => 'vqf',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'vw'  => array(
                            'pattern'   => '^wvpk',
                            'group'     => 'audio',
                            'module'    => 'wavpack',
                            'mime_type' => 'application/octet-stream',
                          ),


                

                
                'asf'  => array (
                            'pattern'   => '^\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C',
                            'group'     => 'audio-video',
                            'module'    => 'asf',
                            'mime_type' => 'video/x-ms-asf',
                          ),

                
                'bink' => array(
                            'pattern'   => '^(BIK|SMK)',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'flv' => array(
                            'pattern'   => '^FLV\x01',
                            'group'     => 'audio-video',
                            'module'    => 'flv',
                            'mime_type' => 'video/x-flv',
                          ),

                
                'matroska' => array (
                            'pattern'   => '^\x1A\x45\xDF\xA3',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'mpeg' => array (
                            'pattern'   => '^\x00\x00\x01(\xBA|\xB3)',
                            'group'     => 'audio-video',
                            'module'    => 'mpeg',
                            'mime_type' => 'video/mpeg',
                          ),

                
                'nsv'  => array (
                            'pattern'   => '^NSV[sf]',
                            'group'     => 'audio-video',
                            'module'    => 'nsv',
                            'mime_type' => 'application/octet-stream',
                          ),

                
                'ogg'  => array (
                            'pattern'   => '^OggS',
                            'group'     => 'audio',
                            'module'    => 'xiph',
                            'mime_type' => 'application/ogg',
                            'fail_id3'  => 'WARNING',
                            'fail_ape'  => 'WARNING',
                          ),

                
                'quicktime' => array (
                            'pattern'   => '^.{4}(cmov|free|ftyp|mdat|moov|pnot|skip|wide)',
                            'group'     => 'audio-video',
                            'module'    => 'quicktime',
                            'mime_type' => 'video/quicktime',
                          ),

                
                'riff' => array (
                            'pattern'   => '^(RIFF|SDSS|FORM)',
                            'group'     => 'audio-video',
                            'module'    => 'riff',
                            'mime_type' => 'audio/x-wave',
                            'fail_ape'  => 'WARNING',
                          ),

                
                'real' => array (
                            'pattern'   => '^(\.RMF|.ra)',
                            'group'     => 'audio-video',
                            'module'    => 'real',
                            'mime_type' => 'audio/x-realaudio',
                          ),

                
                'swf' => array (
                            'pattern'   => '^(F|C)WS',
                            'group'     => 'audio-video',
                            'module'    => 'swf',
                            'mime_type' => 'application/x-shockwave-flash',
                          ),


                

                
                'bmp'  => array (
                            'pattern'   => '^BM',
                            'group'     => 'graphic',
                            'module'    => 'bmp',
                            'mime_type' => 'image/bmp',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'gif'  => array (
                            'pattern'   => '^GIF',
                            'group'     => 'graphic',
                            'module'    => 'gif',
                            'mime_type' => 'image/gif',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'jpeg'  => array (
                            'pattern'   => '^\xFF\xD8\xFF',
                            'group'     => 'graphic',
                            'module'    => 'jpeg',
                            'mime_type' => 'image/jpeg',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'pcd'  => array (
                            'pattern'   => '^.{2048}PCD_IPI\x00',
                            'group'     => 'graphic',
                            'module'    => 'pcd',
                            'mime_type' => 'image/x-photo-cd',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),


                
                'png'  => array (
                            'pattern'   => '^\x89\x50\x4E\x47\x0D\x0A\x1A\x0A',
                            'group'     => 'graphic',
                            'module'    => 'png',
                            'mime_type' => 'image/png',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),


                
				'svg'  => array(
							'pattern'   => '<!DOCTYPE svg PUBLIC ',
							'mime_type' => 'image/svg+xml',
							'fail_id3'  => 'ERROR',
							'fail_ape'  => 'ERROR',
						),


                
                'tiff' => array (
                            'pattern'   => '^(II\x2A\x00|MM\x00\x2A)',
                            'group'     => 'graphic',
                            'module'    => 'tiff',
                            'mime_type' => 'image/tiff',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),


                

                'exe'  => array(
                            'pattern'   => '^MZ',
                            'mime_type' => 'application/octet-stream',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'iso'  => array (
                            'pattern'   => '^.{32769}CD001',
                            'group'     => 'misc',
                            'module'    => 'iso',
                            'mime_type' => 'application/octet-stream',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'rar'  => array(
                            'pattern'   => '^Rar\!',
                            'mime_type' => 'application/octet-stream',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'szip' => array (
                            'pattern'   => '^SZ\x0A\x04',
                            'group'     => 'archive',
                            'module'    => 'szip',
                            'mime_type' => 'application/octet-stream',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'tar'  => array(
                            'pattern'   => '^.{100}[0-9\x20]{7}\x00[0-9\x20]{7}\x00[0-9\x20]{7}\x00[0-9\x20\x00]{12}[0-9\x20\x00]{12}',
                            'group'     => 'archive',
                            'module'    => 'tar',
                            'mime_type' => 'application/x-tar',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                
                'gz'  => array(
                            'pattern'   => '^\x1F\x8B\x08',
                            'group'     => 'archive',
                            'module'    => 'gzip',
                            'mime_type' => 'application/x-gzip',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),


                
                'zip'  => array (
                            'pattern'   => '^PK\x03\x04',
                            'group'     => 'archive',
                            'module'    => 'zip',
                            'mime_type' => 'application/zip',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),


                
                'par2' => array (
                			'pattern'   => '^PAR2\x00PKT',
							'mime_type' => 'application/octet-stream',
							'fail_id3'  => 'ERROR',
							'fail_ape'  => 'ERROR',
						),


                 
                 'pdf' => array(
                            'pattern'   => '^\x25PDF',
                            'mime_type' => 'application/pdf',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                           ),

                 
                 'msoffice' => array(
                            'pattern'   => '^\xD0\xCF\x11\xE0', 
                            'mime_type' => 'application/octet-stream',
                            'fail_id3'  => 'ERROR',
                            'fail_ape'  => 'ERROR',
                          ),

                 
                 'cue' => array(
                            'pattern'   => '', 
                            'group'     => 'misc',
                            'module'    => 'cue',
                            'mime_type' => 'application/octet-stream',
                           ),

            );

        return $format_info;
    }



    
    function CharConvert(&$array, $encoding) {

        
        if ($encoding == $this->encoding) {
            return;
        }

        
        foreach ($array as $key => $value) {

            
            if (is_array($value)) {
                $this->CharConvert($array[$key], $encoding);
            }

            
            elseif (is_string($value)) {
                $array[$key] = $this->iconv($encoding, $this->encoding, $value);
            }
        }
    }



	protected function ChannelsBitratePlaytimeCalculations() {

		
		if (@$this->info['audio']['channels'] == '1') {
			$this->info['audio']['channelmode'] = 'mono';
		} elseif (@$this->info['audio']['channels'] == '2') {
			$this->info['audio']['channelmode'] = 'stereo';
		}

		
		$CombinedBitrate  = 0;
		$CombinedBitrate += (isset($this->info['audio']['bitrate']) ? $this->info['audio']['bitrate'] : 0);
		$CombinedBitrate += (isset($this->info['video']['bitrate']) ? $this->info['video']['bitrate'] : 0);
		if (($CombinedBitrate > 0) && empty($this->info['bitrate'])) {
			$this->info['bitrate'] = $CombinedBitrate;
		}
		
		
		
		
		

		
		if (isset($this->info['video']['dataformat']) && $this->info['video']['dataformat'] && (!isset($this->info['video']['bitrate']) || ($this->info['video']['bitrate'] == 0))) {
			
			if (isset($this->info['audio']['bitrate']) && ($this->info['audio']['bitrate'] > 0) && ($this->info['audio']['bitrate'] == $this->info['bitrate'])) {
				
				if (isset($this->info['playtime_seconds']) && ($this->info['playtime_seconds'] > 0)) {
					
					if (isset($this->info['avdataend']) && isset($this->info['avdataoffset'])) {
						
						
						$this->info['bitrate'] = round((($this->info['avdataend'] - $this->info['avdataoffset']) * 8) / $this->info['playtime_seconds']);
						$this->info['video']['bitrate'] = $this->info['bitrate'] - $this->info['audio']['bitrate'];
					}
				}
			}
		}

		if ((!isset($this->info['playtime_seconds']) || ($this->info['playtime_seconds'] <= 0)) && !empty($this->info['bitrate'])) {
			$this->info['playtime_seconds'] = (($this->info['avdataend'] - $this->info['avdataoffset']) * 8) / $this->info['bitrate'];
		}

		if (!isset($this->info['bitrate']) && !empty($this->info['playtime_seconds'])) {
			$this->info['bitrate'] = (($this->info['avdataend'] - $this->info['avdataoffset']) * 8) / $this->info['playtime_seconds'];
		}





		if (isset($this->info['bitrate']) && empty($this->info['audio']['bitrate']) && empty($this->info['video']['bitrate'])) {
			if (isset($this->info['audio']['dataformat']) && empty($this->info['video']['resolution_x'])) {
				
				$this->info['audio']['bitrate'] = $this->info['bitrate'];
			} elseif (isset($this->info['video']['resolution_x']) && empty($this->info['audio']['dataformat'])) {
				
				$this->info['video']['bitrate'] = $this->info['bitrate'];
			}
		}

		
		if (!empty($this->info['playtime_seconds']) && empty($this->info['playtime_string'])) {
			$this->info['playtime_string'] = getid3_lib::PlaytimeString($this->info['playtime_seconds']);
		}
	}


	protected function CalculateCompressionRatioVideo() {
		if (empty($this->info['video'])) {
			return false;
		}
		if (empty($this->info['video']['resolution_x']) || empty($this->info['video']['resolution_y'])) {
			return false;
		}
		if (empty($this->info['video']['bits_per_sample'])) {
			return false;
		}

		switch ($this->info['video']['dataformat']) {
			case 'bmp':
			case 'gif':
			case 'jpeg':
			case 'jpg':
			case 'png':
			case 'tiff':
				$FrameRate = 1;
				$PlaytimeSeconds = 1;
				$BitrateCompressed = $this->info['filesize'] * 8;
				break;

			default:
				if (!empty($this->info['video']['frame_rate'])) {
					$FrameRate = $this->info['video']['frame_rate'];
				} else {
					return false;
				}
				if (!empty($this->info['playtime_seconds'])) {
					$PlaytimeSeconds = $this->info['playtime_seconds'];
				} else {
					return false;
				}
				if (!empty($this->info['video']['bitrate'])) {
					$BitrateCompressed = $this->info['video']['bitrate'];
				} else {
					return false;
				}
				break;
		}
		$BitrateUncompressed = $this->info['video']['resolution_x'] * $this->info['video']['resolution_y'] * $this->info['video']['bits_per_sample'] * $FrameRate;

		$this->info['video']['compression_ratio'] = $BitrateCompressed / $BitrateUncompressed;
		return true;
	}


	protected function CalculateCompressionRatioAudio() {
		if (empty($this->info['audio']['bitrate']) || empty($this->info['audio']['channels']) || empty($this->info['audio']['sample_rate'])) {
			return false;
		}
		$this->info['audio']['compression_ratio'] = $this->info['audio']['bitrate'] / ($this->info['audio']['channels'] * $this->info['audio']['sample_rate'] * (!empty($this->info['audio']['bits_per_sample']) ? $this->info['audio']['bits_per_sample'] : 16));

		if (!empty($this->info['audio']['streams'])) {
			foreach ($this->info['audio']['streams'] as $streamnumber => $streamdata) {
				if (!empty($streamdata['bitrate']) && !empty($streamdata['channels']) && !empty($streamdata['sample_rate'])) {
					$this->info['audio']['streams'][$streamnumber]['compression_ratio'] = $streamdata['bitrate'] / ($streamdata['channels'] * $streamdata['sample_rate'] * (!empty($streamdata['bits_per_sample']) ? $streamdata['bits_per_sample'] : 16));
				}
			}
		}
		return true;
	}


	protected function CalculateReplayGain() {
		if (isset($this->info['replay_gain'])) {
			$this->info['replay_gain']['reference_volume'] = 89;
			if (isset($this->info['replay_gain']['track']['adjustment'])) {
				$this->info['replay_gain']['track']['volume'] = $this->info['replay_gain']['reference_volume'] - $this->info['replay_gain']['track']['adjustment'];
			}
			if (isset($this->info['replay_gain']['album']['adjustment'])) {
				$this->info['replay_gain']['album']['volume'] = $this->info['replay_gain']['reference_volume'] - $this->info['replay_gain']['album']['adjustment'];
			}

			if (isset($this->info['replay_gain']['track']['peak'])) {
				$this->info['replay_gain']['track']['max_noclip_gain'] = 0 - getid3_lib::RGADamplitude2dB($this->info['replay_gain']['track']['peak']);
			}
			if (isset($this->info['replay_gain']['album']['peak'])) {
				$this->info['replay_gain']['album']['max_noclip_gain'] = 0 - getid3_lib::RGADamplitude2dB($this->info['replay_gain']['album']['peak']);
			}
		}
		return true;
	}

	protected function ProcessAudioStreams() {
		if (!empty($this->info['audio']['bitrate']) || !empty($this->info['audio']['channels']) || !empty($this->info['audio']['sample_rate'])) {
			if (!isset($this->info['audio']['streams'])) {
				foreach ($this->info['audio'] as $key => $value) {
					if ($key != 'streams') {
						$this->info['audio']['streams'][0][$key] = $value;
					}
				}
			}
		}
		return true;
	}


    
    protected function HandleAllTags() {

        
        static $tags = array (
            'asf'       => array ('asf',           'UTF-16LE'),
            'midi'      => array ('midi',          'ISO-8859-1'),
            'nsv'       => array ('nsv',           'ISO-8859-1'),
            'ogg'       => array ('vorbiscomment', 'UTF-8'),
            'png'       => array ('png',           'UTF-8'),
            'tiff'      => array ('tiff',          'ISO-8859-1'),
            'quicktime' => array ('quicktime',     'UTF-8'),
            'real'      => array ('real',          'ISO-8859-1'),
            'vqf'       => array ('vqf',           'ISO-8859-1'),
            'zip'       => array ('zip',           'ISO-8859-1'),
            'riff'      => array ('riff',          'ISO-8859-1'),
            'lyrics3'   => array ('lyrics3',       'ISO-8859-1'),
            'id3v1'     => array ('id3v1',         ''),            
            'id3v2'     => array ('id3v2',         'UTF-8'),       
            'ape'       => array ('ape',           'UTF-8'),
            'cue'       => array ('cue',           'ISO-8859-1'),
        );
        $tags['id3v1'][1] = $this->encoding_id3v1;

        
        foreach ($tags as $comment_name => $tag_name_encoding_array) {
            list($tag_name, $encoding) = $tag_name_encoding_array;

            
            @$this->info[$comment_name]  and  $this->info[$comment_name]['encoding'] = $encoding;

            
            if (@$this->info[$comment_name]['comments']) {

                foreach ($this->info[$comment_name]['comments'] as $tag_key => $value_array) {
                    foreach ($value_array as $key => $value) {
                        if (strlen(trim($value)) > 0) {
                            $this->info['tags'][$tag_name][trim($tag_key)][] = $value; 
                        }
                    }

                }

                if (!@$this->info['tags'][$tag_name]) {
                    
                    continue;
                }

                $this->CharConvert($this->info['tags'][$tag_name], $encoding);
            }
        }


        
        if (@$this->info['tags']) {

            foreach ($this->info['tags'] as $tag_type => $tag_array) {

                foreach ($tag_array as $tag_name => $tagdata) {

                    foreach ($tagdata as $key => $value) {

                        if (!empty($value)) {

                            if (empty($this->info['comments'][$tag_name])) {

                                
                            }
                            elseif ($tag_type == 'id3v1') {

                                $new_value_length = strlen(trim($value));
                                foreach ($this->info['comments'][$tag_name] as $existing_key => $existing_value) {
                                    $old_value_length = strlen(trim($existing_value));
                                    if (($new_value_length <= $old_value_length) && (substr($existing_value, 0, $new_value_length) == trim($value))) {
                                        
                                        break 2;
                                    }
                                }
                            }
                            else {

                                $new_value_length = strlen(trim($value));
                                foreach ($this->info['comments'][$tag_name] as $existing_key => $existing_value) {
                                    $old_value_length = strlen(trim($existing_value));
                                    if (($new_value_length > $old_value_length) && (substr(trim($value), 0, strlen($existing_value)) == $existing_value)) {
                                        $this->info['comments'][$tag_name][$existing_key] = trim($value);
                                        break 2;
                                    }
                                }
                            }

                            if (empty($this->info['comments'][$tag_name]) || !in_array(trim($value), $this->info['comments'][$tag_name])) {
                                $this->info['comments'][$tag_name][] = trim($value);
                            }
                        }
                    }
                }
            }
        }

        return true;
    }
}


abstract class getid3_handler
{

    protected $getid3;                          

    protected $data_string_flag = false;        
    protected $data_string;                     
    protected $data_string_position = 0;        


    public function __construct(getID3 $getid3) {

        $this->getid3 = $getid3;
    }


    
    abstract public function Analyze();



    
    public function AnalyzeString(&$string) {

        
        $this->data_string_flag = true;
        $this->data_string      = $string;

        
        $saved_avdataoffset = $this->getid3->info['avdataoffset'];
        $saved_avdataend    = $this->getid3->info['avdataend'];
        $saved_filesize     = $this->getid3->info['filesize'];

        
        $this->getid3->info['avdataoffset'] = 0;
        $this->getid3->info['avdataend']    = $this->getid3->info['filesize'] = strlen($string);

        
        $this->Analyze();

        
        $this->getid3->info['avdataoffset'] = $saved_avdataoffset;
        $this->getid3->info['avdataend']    = $saved_avdataend;
        $this->getid3->info['filesize']     = $saved_filesize;

        
        $this->data_string_flag = false;
    }


    protected function ftell() {

        if ($this->data_string_flag) {
            return $this->data_string_position;
        }
        return ftell($this->getid3->fp);
    }


    protected function fread($bytes) {

        if ($this->data_string_flag) {
            $this->data_string_position += $bytes;
            return substr($this->data_string, $this->data_string_position - $bytes, $bytes);
        }
        return fread($this->getid3->fp, $bytes);
    }


    protected function fseek($bytes, $whence = SEEK_SET) {

        if ($this->data_string_flag) {
            switch ($whence) {
                case SEEK_SET:
                    $this->data_string_position = $bytes;
                    return;

                case SEEK_CUR:
                    $this->data_string_position += $bytes;
                    return;

                case SEEK_END:
                    $this->data_string_position = strlen($this->data_string) + $bytes;
                    return;
            }
        }
        return fseek($this->getid3->fp, $bytes, $whence);
    }

}




abstract class getid3_handler_write
{
    protected $filename;
    protected $user_abort;

    private $fp_lock;
    private $owner;
    private $group;
    private $perms;


    public function __construct($filename) {

        if (!file_exists($filename)) {
            throw new getid3_exception('File does not exist: "' . $filename . '"');
        }

        if (!is_writeable($filename)) {
            throw new getid3_exception('File is not writeable: "' . $filename . '"');
        }

        if (!is_writeable(dirname($filename))) {
            throw new getid3_exception('Directory is not writeable: ' . dirname($filename) . ' (need to write lock file).');
        }

        $this->user_abort = ignore_user_abort(true);

        $this->fp_lock = fopen($filename . '.getid3.lock', 'w');
        flock($this->fp_lock, LOCK_EX);

        $this->filename = $filename;
    }


    public function __destruct() {

        flock($this->fp_lock, LOCK_UN);
        fclose($this->fp_lock);
        unlink($this->filename . '.getid3.lock');

        ignore_user_abort($this->user_abort);
    }


    protected function save_permissions() {

        $this->owner = fileowner($this->filename);
        $this->group = filegroup($this->filename);
        $this->perms = fileperms($this->filename);
    }


    protected function restore_permissions() {

        @chown($this->filename, $this->owner);
        @chgrp($this->filename, $this->group);
        @chmod($this->filename, $this->perms);
    }


    abstract public function read();

    abstract public function write();

    abstract public function remove();

}




class getid3_exception extends Exception
{
    public $message;

}




class getid3_lib
{

    
    public static function LittleEndian2Int($byte_word, $signed = false) {

        return getid3_lib::BigEndian2Int(strrev($byte_word), $signed);
    }



    
    public static function LittleEndian2String($number, $minbytes=1, $synchsafe=false) {
        $intstring = '';
        while ($number > 0) {
            if ($synchsafe) {
                $intstring = $intstring.chr($number & 127);
                $number >>= 7;
            } else {
                $intstring = $intstring.chr($number & 255);
                $number >>= 8;
            }
        }
        return str_pad($intstring, $minbytes, "\x00", STR_PAD_RIGHT);
    }



    
    public static function BigEndian2Int($byte_word, $signed = false) {

        $int_value = 0;
        $byte_wordlen = strlen($byte_word);
		if ($byte_wordlen == 0) {
			return false;
		}

        for ($i = 0; $i < $byte_wordlen; $i++) {
            $int_value += ord($byte_word{$i}) * pow(256, ($byte_wordlen - 1 - $i));
        }

        if ($signed) {
        	
            $sign_mask_bit = 0x80 << (8 * ($byte_wordlen - 1));
            if ($int_value & $sign_mask_bit) {
                $int_value = 0 - ($int_value & ($sign_mask_bit - 1));
            }
        }

        return $int_value;
    }



    
    public static function BigEndianSyncSafe2Int($byte_word) {

        $int_value = 0;
        $byte_wordlen = strlen($byte_word);

        
        for ($i = 0; $i < $byte_wordlen; $i++) {
            $int_value = $int_value | (ord($byte_word{$i}) & 0x7F) << (($byte_wordlen - 1 - $i) * 7);
        }
        return $int_value;
    }



    
    public static function BigEndian2Bin($byte_word) {

        $bin_value = '';
        $byte_wordlen = strlen($byte_word);
        for ($i = 0; $i < $byte_wordlen; $i++) {
            $bin_value .= str_pad(decbin(ord($byte_word{$i})), 8, '0', STR_PAD_LEFT);
        }
        return $bin_value;
    }



    public static function BigEndian2Float($byte_word) {

		
		
		

		$bit_word = getid3_lib::BigEndian2Bin($byte_word);
		if (!$bit_word) {
            return 0;
        }
		$sign_bit = $bit_word{0};

		switch (strlen($byte_word) * 8) {
			case 32:
				$exponent_bits = 8;
				$fraction_bits = 23;
				break;

			case 64:
				$exponent_bits = 11;
				$fraction_bits = 52;
				break;

			case 80:
				
				
				$exponent_string = substr($bit_word, 1, 15);
				$is_normalized = intval($bit_word{16});
				$fraction_string = substr($bit_word, 17, 63);
				$exponent = pow(2, getid3_lib::Bin2Dec($exponent_string) - 16383);
				$fraction = $is_normalized + getid3_lib::DecimalBinary2Float($fraction_string);
				$float_value = $exponent * $fraction;
				if ($sign_bit == '1') {
					$float_value *= -1;
				}
				return $float_value;
				break;

			default:
				return false;
				break;
		}
		$exponent_string = substr($bit_word, 1, $exponent_bits);
		$fraction_string = substr($bit_word, $exponent_bits + 1, $fraction_bits);
		$exponent = bindec($exponent_string);
		$fraction = bindec($fraction_string);

		if (($exponent == (pow(2, $exponent_bits) - 1)) && ($fraction != 0)) {
			
			$float_value = false;
		} elseif (($exponent == (pow(2, $exponent_bits) - 1)) && ($fraction == 0)) {
			if ($sign_bit == '1') {
				$float_value = '-infinity';
			} else {
				$float_value = '+infinity';
			}
		} elseif (($exponent == 0) && ($fraction == 0)) {
			if ($sign_bit == '1') {
				$float_value = -0;
			} else {
				$float_value = 0;
			}
			$float_value = ($sign_bit ? 0 : -0);
		} elseif (($exponent == 0) && ($fraction != 0)) {
			
			$float_value = pow(2, (-1 * (pow(2, $exponent_bits - 1) - 2))) * getid3_lib::DecimalBinary2Float($fraction_string);
			if ($sign_bit == '1') {
				$float_value *= -1;
			}
		} elseif ($exponent != 0) {
			$float_value = pow(2, ($exponent - (pow(2, $exponent_bits - 1) - 1))) * (1 + getid3_lib::DecimalBinary2Float($fraction_string));
			if ($sign_bit == '1') {
				$float_value *= -1;
			}
		}
		return (float) $float_value;
	}



	public static function LittleEndian2Float($byte_word) {

		return getid3_lib::BigEndian2Float(strrev($byte_word));
	}



	public static function DecimalBinary2Float($binary_numerator) {
		$numerator   = bindec($binary_numerator);
		$denominator = bindec('1'.str_repeat('0', strlen($binary_numerator)));
		return ($numerator / $denominator);
	}


	public static function RGADamplitude2dB($amplitude) {
		return 20 * log10($amplitude);
	}


	public static function PrintHexBytes($string, $hex=true, $spaces=true, $html_safe=true) {

        $return_string = '';
        for ($i = 0; $i < strlen($string); $i++) {
            if ($hex) {
                $return_string .= str_pad(dechex(ord($string{$i})), 2, '0', STR_PAD_LEFT);
            } else {
                $return_string .= ' '.(preg_match("#[\x20-\x7E]#", $string{$i}) ? $string{$i} : '§');
            }
            if ($spaces) {
                $return_string .= ' ';
            }
        }
        if ($html_safe) {
            $return_string = htmlentities($return_string);
        }
        return $return_string;
    }


	public static function PlaytimeString($playtimeseconds) {
		$sign = (($playtimeseconds < 0) ? '-' : '');
		$playtimeseconds = abs($playtimeseconds);
		$contentseconds = round((($playtimeseconds / 60) - floor($playtimeseconds / 60)) * 60);
		$contentminutes = floor($playtimeseconds / 60);
		if ($contentseconds >= 60) {
			$contentseconds -= 60;
			$contentminutes++;
		}
		return $sign.intval($contentminutes).':'.str_pad($contentseconds, 2, 0, STR_PAD_LEFT);
	}


    
    
    
    
    

    public static function ReadSequence($algorithm, &$target, &$data, $offset, $parts_array) {

        
        foreach ($parts_array as $target_string => $length) {

            
            if (!strstr($target_string, 'IGNORE')) {

                
                if ($length < 0) {
                    $target[$target_string] = substr($data, $offset, -$length);
                }

                
                else {
                    $target[$target_string] = getid3_lib::$algorithm(substr($data, $offset, $length));
                }
            }

            
            $offset += abs($length);
        }
    }

}



class getid3_lib_replaygain
{

    public static function NameLookup($name_code) {

        static $lookup = array (
            0 => 'not set',
            1 => 'Track Gain Adjustment',
            2 => 'Album Gain Adjustment'
        );

        return @$lookup[$name_code];
    }



    public static function OriginatorLookup($originator_code) {

        static $lookup = array (
            0 => 'unspecified',
            1 => 'pre-set by artist/producer/mastering engineer',
            2 => 'set by user',
            3 => 'determined automatically'
        );

        return @$lookup[$originator_code];
    }



    public static function AdjustmentLookup($raw_adjustment, $sign_bit) {

        return (float)$raw_adjustment / 10 * ($sign_bit == 1 ? -1 : 1);
    }



    public static function GainString($name_code, $originator_code, $replaygain) {

        $sign_bit = $replaygain < 0 ? 1 : 0;

        $stored_replaygain = intval(round($replaygain * 10));
        $gain_string  = str_pad(decbin($name_code), 3, '0', STR_PAD_LEFT);
        $gain_string .= str_pad(decbin($originator_code), 3, '0', STR_PAD_LEFT);
        $gain_string .= $sign_bit;
        $gain_string .= str_pad(decbin($stored_replaygain), 9, '0', STR_PAD_LEFT);

        return $gain_string;
    }

}

class getid3_riff extends getid3_handler
{

	private $endian_function = 'LittleEndian2Int';


	public function Analyze() {

		$getid3 = $this->getid3;

		$getid3->info['riff']['raw'] = array ();
		$info_riff             = &$getid3->info['riff'];
		$info_riff_raw         = &$info_riff['raw'];
		$info_audio            = &$getid3->info['audio'];
		$info_video            = &$getid3->info['video'];
		$info_avdataoffset     = &$getid3->info['avdataoffset'];
		$info_avdataend        = &$getid3->info['avdataend'];
		$info_audio_dataformat = &$info_audio['dataformat'];
		$info_riff_audio       = &$info_riff['audio'];
		$info_riff_video       = &$info_riff['video'];

		$original['avdataend'] = $info_avdataend;

		$this->fseek($info_avdataoffset, SEEK_SET);
		$riff_header   = $this->fread(12);

		$riff_sub_type = substr($riff_header, 8, 4);

		switch (substr($riff_header, 0, 4)) {

			case 'FORM':
				$getid3->info['fileformat'] = 'aiff';
				$this->endian_function      = 'BigEndian2Int';
				$riff_header_size           = getid3_lib::BigEndian2Int(substr($riff_header, 4, 4));
				$info_riff[$riff_sub_type]  = $this->ParseRIFF($info_avdataoffset + 12, $info_avdataoffset + $riff_header_size);
				$info_riff['header_size']   = $riff_header_size;
				break;


			case 'RIFF':
			case 'SDSS':  // SDSS is identical to RIFF, just renamed. Used by SmartSound QuickTracks (www.smartsound.com)
			case 'RMP3':  // RMP3 is identical to RIFF, just renamed. Used by [unknown program] when creating RIFF-MP3s

				if ($riff_sub_type == 'RMP3') {
					$riff_sub_type = 'WAVE';
				}

				$getid3->info['fileformat'] = 'riff';
				$this->endian_function      = 'LittleEndian2Int';
				$riff_header_size           = getid3_lib::LittleEndian2Int(substr($riff_header, 4, 4));
				$info_riff[$riff_sub_type]  = $this->ParseRIFF($info_avdataoffset + 12, $info_avdataoffset + $riff_header_size);
				$info_riff['header_size']   = $riff_header_size;
				if ($riff_sub_type == 'WAVE') {
					$info_riff_wave = &$info_riff['WAVE'];
				}
				break;


			default:
				throw new getid3_exception('Cannot parse RIFF (this is maybe not a RIFF / WAV / AVI file?) - expecting "FORM|RIFF|SDSS|RMP3" found "'.$riff_sub_type.'" instead');
		}

		$endian_function = $this->endian_function;

		$stream_index = 0;
		switch ($riff_sub_type) {

			case 'WAVE':

				if (empty($info_audio['bitrate_mode'])) {
					$info_audio['bitrate_mode'] = 'cbr';
				}

				if (empty($info_audio_dataformat)) {
					$info_audio_dataformat = 'wav';
				}

				if (isset($info_riff_wave['data'][0]['offset'])) {
					$info_avdataoffset = $info_riff_wave['data'][0]['offset'] + 8;
					$info_avdataend    = $info_avdataoffset + $info_riff_wave['data'][0]['size'];
				}

				if (isset($info_riff_wave['fmt '][0]['data'])) {

					$info_riff_audio[$stream_index] = getid3_riff::RIFFparseWAVEFORMATex($info_riff_wave['fmt '][0]['data']);
					$info_audio['wformattag'] = $info_riff_audio[$stream_index]['raw']['wFormatTag'];
					$info_riff_raw['fmt '] = $info_riff_audio[$stream_index]['raw'];
					unset($info_riff_audio[$stream_index]['raw']);
					$info_audio['streams'][$stream_index] = $info_riff_audio[$stream_index];

					$info_audio = getid3_riff::array_merge_noclobber($info_audio, $info_riff_audio[$stream_index]);
					if (substr($info_audio['codec'], 0, strlen('unknown: 0x')) == 'unknown: 0x') {
						$getid3->warning('Audio codec = '.$info_audio['codec']);
					}
					$info_audio['bitrate'] = $info_riff_audio[$stream_index]['bitrate'];

					$getid3->info['playtime_seconds'] = (float)((($info_avdataend - $info_avdataoffset) * 8) / $info_audio['bitrate']);

					$info_audio['lossless'] = false;

					if (isset($info_riff_wave['data'][0]['offset']) && isset($info_riff_raw['fmt ']['wFormatTag'])) {

						switch ($info_riff_raw['fmt ']['wFormatTag']) {

							case 0x0001:  // PCM
								$info_audio['lossless'] = true;
								break;

							case 0x2000:  // AC-3
								$info_audio_dataformat = 'ac3';
								break;

							default:
								// do nothing
								break;

						}
					}

					$info_audio['streams'][$stream_index]['wformattag']   = $info_audio['wformattag'];
					$info_audio['streams'][$stream_index]['bitrate_mode'] = $info_audio['bitrate_mode'];
					$info_audio['streams'][$stream_index]['lossless']     = $info_audio['lossless'];
					$info_audio['streams'][$stream_index]['dataformat']   = $info_audio_dataformat;
				}


				if (isset($info_riff_wave['rgad'][0]['data'])) {

					// shortcuts
					$rgadData = &$info_riff_wave['rgad'][0]['data'];
					$info_riff_raw['rgad']    = array ('track'=>array(), 'album'=>array());
					$info_riff_raw_rgad       = &$info_riff_raw['rgad'];
					$info_riff_raw_rgad_track = &$info_riff_raw_rgad['track'];
					$info_riff_raw_rgad_album = &$info_riff_raw_rgad['album'];

					$info_riff_raw_rgad['fPeakAmplitude']      = getid3_riff::BigEndian2Float(strrev(substr($rgadData, 0, 4)));   // LittleEndian2Float()
					$info_riff_raw_rgad['nRadioRgAdjust']      = getid3_lib::$endian_function(substr($rgadData, 4, 2));
					$info_riff_raw_rgad['nAudiophileRgAdjust'] = getid3_lib::$endian_function(substr($rgadData, 6, 2));

					$n_track_rg_adjust_bit_string              = str_pad(decbin($info_riff_raw_rgad['nRadioRgAdjust']),      16, '0', STR_PAD_LEFT);
					$n_album_rg_adjust_bit_string              = str_pad(decbin($info_riff_raw_rgad['nAudiophileRgAdjust']), 16, '0', STR_PAD_LEFT);

					$info_riff_raw_rgad_track['name']          = bindec(substr($n_track_rg_adjust_bit_string, 0, 3));
					$info_riff_raw_rgad_track['originator']    = bindec(substr($n_track_rg_adjust_bit_string, 3, 3));
					$info_riff_raw_rgad_track['signbit']       = bindec($n_track_rg_adjust_bit_string[6]);
					$info_riff_raw_rgad_track['adjustment']    = bindec(substr($n_track_rg_adjust_bit_string, 7, 9));
					$info_riff_raw_rgad_album['name']          = bindec(substr($n_album_rg_adjust_bit_string, 0, 3));
					$info_riff_raw_rgad_album['originator']    = bindec(substr($n_album_rg_adjust_bit_string, 3, 3));
					$info_riff_raw_rgad_album['signbit']       = bindec($n_album_rg_adjust_bit_string[6]);
					$info_riff_raw_rgad_album['adjustment']    = bindec(substr($n_album_rg_adjust_bit_string, 7, 9));

					$info_riff['rgad']['peakamplitude'] = $info_riff_raw_rgad['fPeakAmplitude'];
					if (($info_riff_raw_rgad_track['name'] != 0) && ($info_riff_raw_rgad_track['originator'] != 0)) {
						$info_riff['rgad']['track']['name']       = getid3_lib_replaygain::NameLookup($info_riff_raw_rgad_track['name']);
						$info_riff['rgad']['track']['originator'] = getid3_lib_replaygain::OriginatorLookup($info_riff_raw_rgad_track['originator']);
						$info_riff['rgad']['track']['adjustment'] = getid3_lib_replaygain::AdjustmentLookup($info_riff_raw_rgad_track['adjustment'], $info_riff_raw_rgad_track['signbit']);
					}

					if (($info_riff_raw_rgad_album['name'] != 0) && ($info_riff_raw_rgad_album['originator'] != 0)) {
						$info_riff['rgad']['album']['name']       = getid3_lib_replaygain::NameLookup($info_riff_raw_rgad_album['name']);
						$info_riff['rgad']['album']['originator'] = getid3_lib_replaygain::OriginatorLookup($info_riff_raw_rgad_album['originator']);
						$info_riff['rgad']['album']['adjustment'] = getid3_lib_replaygain::AdjustmentLookup($info_riff_raw_rgad_album['adjustment'], $info_riff_raw_rgad_album['signbit']);
					}
				}

				if (isset($info_riff_wave['fact'][0]['data'])) {

					$info_riff_raw['fact']['NumberOfSamples'] = getid3_lib::$endian_function(substr($info_riff_wave['fact'][0]['data'], 0, 4));

					// This should be a good way of calculating exact playtime, but some sample files have had incorrect number of samples, so cannot use this method
					// if (!empty($info_riff_raw['fmt ']['nSamplesPerSec'])) {
					//     $getid3->info['playtime_seconds'] = (float)$info_riff_raw['fact']['NumberOfSamples'] / $info_riff_raw['fmt ']['nSamplesPerSec'];
					// }
				}


				if (!empty($info_riff_raw['fmt ']['nAvgBytesPerSec'])) {
					$info_audio['bitrate'] = (int)$info_riff_raw['fmt ']['nAvgBytesPerSec'] * 8;
				}

				if (isset($info_riff_wave['bext'][0]['data'])) {

					$info_riff_wave_bext_0 = &$info_riff_wave['bext'][0];

					getid3_lib::ReadSequence('LittleEndian2Int', $info_riff_wave_bext_0, $info_riff_wave_bext_0['data'], 0,
						array (
							'title'          => -256,
							'author'         =>  -32,
							'reference'      =>  -32,
							'origin_date'    =>  -10,
							'origin_time'    =>   -8,
							'time_reference' =>    8,
							'bwf_version'    =>    1,
							'reserved'       =>  254
						)
					);

					foreach (array ('title', 'author', 'reference') as $key) {
						$info_riff_wave_bext_0[$key] = trim($info_riff_wave_bext_0[$key]);
					}

					$info_riff_wave_bext_0['coding_history'] = explode("\r\n", trim(substr($info_riff_wave_bext_0['data'], 601)));

					$info_riff_wave_bext_0['origin_date_unix'] = gmmktime(substr($info_riff_wave_bext_0['origin_time'], 0, 2),
																		  substr($info_riff_wave_bext_0['origin_time'], 3, 2),
																		  substr($info_riff_wave_bext_0['origin_time'], 6, 2),
																		  substr($info_riff_wave_bext_0['origin_date'], 5, 2),
																		  substr($info_riff_wave_bext_0['origin_date'], 8, 2),
																		  substr($info_riff_wave_bext_0['origin_date'], 0, 4));

					$info_riff['comments']['author'][] = $info_riff_wave_bext_0['author'];
					$info_riff['comments']['title'][]  = $info_riff_wave_bext_0['title'];
				}

				if (isset($info_riff_wave['MEXT'][0]['data'])) {

					$info_riff_wave_mext_0 = &$info_riff_wave['MEXT'][0];

					$info_riff_wave_mext_0['raw']['sound_information']      = getid3_lib::LittleEndian2Int(substr($info_riff_wave_mext_0['data'], 0, 2));
					$info_riff_wave_mext_0['flags']['homogenous']           = (bool)($info_riff_wave_mext_0['raw']['sound_information'] & 0x0001);
					if ($info_riff_wave_mext_0['flags']['homogenous']) {
						$info_riff_wave_mext_0['flags']['padding']          = ($info_riff_wave_mext_0['raw']['sound_information'] & 0x0002) ? false : true;
						$info_riff_wave_mext_0['flags']['22_or_44']         = (bool)($info_riff_wave_mext_0['raw']['sound_information'] & 0x0004);
						$info_riff_wave_mext_0['flags']['free_format']      = (bool)($info_riff_wave_mext_0['raw']['sound_information'] & 0x0008);

						$info_riff_wave_mext_0['nominal_frame_size']        = getid3_lib::LittleEndian2Int(substr($info_riff_wave_mext_0['data'], 2, 2));
					}
					$info_riff_wave_mext_0['anciliary_data_length']         = getid3_lib::LittleEndian2Int(substr($info_riff_wave_mext_0['data'], 6, 2));
					$info_riff_wave_mext_0['raw']['anciliary_data_def']     = getid3_lib::LittleEndian2Int(substr($info_riff_wave_mext_0['data'], 8, 2));
					$info_riff_wave_mext_0['flags']['anciliary_data_left']  = (bool)($info_riff_wave_mext_0['raw']['anciliary_data_def'] & 0x0001);
					$info_riff_wave_mext_0['flags']['anciliary_data_free']  = (bool)($info_riff_wave_mext_0['raw']['anciliary_data_def'] & 0x0002);
					$info_riff_wave_mext_0['flags']['anciliary_data_right'] = (bool)($info_riff_wave_mext_0['raw']['anciliary_data_def'] & 0x0004);
				}

				if (isset($info_riff_wave['cart'][0]['data'])) {

					$info_riff_wave_cart_0 = &$info_riff_wave['cart'][0];

					getid3_lib::ReadSequence('LittleEndian2Int', $info_riff_wave_cart_0, $info_riff_wave_cart_0['data'], 0,
						array (
							'version'              => -4,
							'title'                => -64,
							'artist'               => -64,
							'cut_id'               => -64,
							'client_id'            => -64,
							'category'             => -64,
							'classification'       => -64,
							'out_cue'              => -64,
							'start_date'           => -10,
							'start_time'           => -8,
							'end_date'             => -10,
							'end_time'             => -8,
							'producer_app_id'      => -64,
							'producer_app_version' => -64,
							'user_defined_text'    => -64,
						)
					);

					foreach (array ('artist', 'cut_id', 'client_id', 'category', 'classification', 'out_cue', 'start_date', 'start_time', 'end_date', 'end_time', 'producer_app_id', 'producer_app_version', 'user_defined_text') as $key) {
						$info_riff_wave_cart_0[$key] = trim($info_riff_wave_cart_0[$key]);
					}

					$info_riff_wave_cart_0['zero_db_reference'] = getid3_lib::LittleEndian2Int(substr($info_riff_wave_cart_0['data'], 680, 4), true);

					for ($i = 0; $i < 8; $i++) {
						$info_riff_wave_cart_0['post_time'][$i]['usage_fourcc'] =                              substr($info_riff_wave_cart_0['data'], 684 + ($i * 8), 4);
						$info_riff_wave_cart_0['post_time'][$i]['timer_value']  = getid3_lib::LittleEndian2Int(substr($info_riff_wave_cart_0['data'], 684 + ($i * 8) + 4, 4));
					}
					$info_riff_wave_cart_0['url']              =                 trim(substr($info_riff_wave_cart_0['data'],  748, 1024));
					$info_riff_wave_cart_0['tag_text']         = explode("\r\n", trim(substr($info_riff_wave_cart_0['data'], 1772)));

					$info_riff['comments']['artist'][] = $info_riff_wave_cart_0['artist'];
					$info_riff['comments']['title'][]  = $info_riff_wave_cart_0['title'];
				}


				if (isset($info_riff_wave['SNDM'][0]['data'])) {
					// SoundMiner metadata

					// shortcuts
					$info_riff_wave_SNDM_0      = &$info_riff_wave['SNDM'][0];
					$info_riff_wave_SNDM_0_data = &$info_riff_wave_SNDM_0['data'];
					$SNDM_startoffset = 0;
					$SNDM_endoffset   = $info_riff_wave_SNDM_0['size'];

					while ($SNDM_startoffset < $SNDM_endoffset) {
						$SNDM_thisTagOffset = 0;
						$SNDM_thisTagSize      = getid3_lib::BigEndian2Int(substr($info_riff_wave_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 4));
						$SNDM_thisTagOffset += 4;
						$SNDM_thisTagKey       =                           substr($info_riff_wave_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 4);
						$SNDM_thisTagOffset += 4;
						$SNDM_thisTagDataSize  = getid3_lib::BigEndian2Int(substr($info_riff_wave_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 2));
						$SNDM_thisTagOffset += 2;
						$SNDM_thisTagDataFlags = getid3_lib::BigEndian2Int(substr($info_riff_wave_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 2));
						$SNDM_thisTagOffset += 2;
						$SNDM_thisTagDataText =                            substr($info_riff_wave_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, $SNDM_thisTagDataSize);
						$SNDM_thisTagOffset += $SNDM_thisTagDataSize;

						if ($SNDM_thisTagSize != (4 + 4 + 2 + 2 + $SNDM_thisTagDataSize)) {
							$getid3->warning('RIFF.WAVE.SNDM.data contains tag not expected length (expected: '.$SNDM_thisTagSize.', found: '.(4 + 4 + 2 + 2 + $SNDM_thisTagDataSize).') at offset '.$SNDM_startoffset.' (file offset '.($info_riff_wave_SNDM_0['offset'] + $SNDM_startoffset).')');
							break;
						} elseif ($SNDM_thisTagSize <= 0) {
							$getid3->warning('RIFF.WAVE.SNDM.data contains zero-size tag at offset '.$SNDM_startoffset.' (file offset '.($info_riff_wave_SNDM_0['offset'] + $SNDM_startoffset).')');
							break;
						}
						$SNDM_startoffset += $SNDM_thisTagSize;

						$info_riff_wave_SNDM_0['parsed_raw'][$SNDM_thisTagKey] = $SNDM_thisTagDataText;
						$parsedkey = $this->RIFFwaveSNDMtagLookup($SNDM_thisTagKey);
						if ($parsedkey) {
							$info_riff_wave_SNDM_0['parsed'][$parsedkey] = $SNDM_thisTagDataText;
						} else {
							$getid3->warning('RIFF.WAVE.SNDM contains unknown tag "'.$SNDM_thisTagKey.'" at offset '.$SNDM_startoffset.' (file offset '.($info_riff_wave_SNDM_0['offset'] + $SNDM_startoffset).')');
						}
					}

					$tagmapping = array(
						'tracktitle'=>'title',
						'category'  =>'genre',
						'cdtitle'   =>'album',
						'tracktitle'=>'title',
					);
					foreach ($tagmapping as $fromkey => $tokey) {
						if (isset($info_riff_wave_SNDM_0['parsed'][$fromkey])) {
							$info_riff['comments'][$tokey][] = $info_riff_wave_SNDM_0['parsed'][$fromkey];
						}
					}
				}


				if (!isset($info_audio['bitrate']) && isset($info_riff_audio[$stream_index]['bitrate'])) {
					$info_audio['bitrate'] = $info_riff_audio[$stream_index]['bitrate'];
					$getid3->info['playtime_seconds'] = (float)((($info_avdataend - $info_avdataoffset) * 8) / $info_audio['bitrate']);
				}

				if (@$getid3->info['wavpack']) {

					if (!$this->data_string_flag)  {

						$info_audio_dataformat      = 'wavpack';
						$info_audio['bitrate_mode'] = 'vbr';
						$info_audio['encoder']      = 'WavPack v'.$getid3->info['wavpack']['version'];

						// Reset to the way it was - RIFF parsing will have messed this up
						$info_avdataend        = $original['avdataend'];
						$info_audio['bitrate'] = (($info_avdataend - $info_avdataoffset) * 8) / $getid3->info['playtime_seconds'];

						$this->fseek($info_avdataoffset - 44, SEEK_SET);
						$riff_data = $this->fread(44);
						$orignal_riff_header_size = getid3_lib::LittleEndian2Int(substr($riff_data,  4, 4)) +  8;
						$orignal_riff_data_size   = getid3_lib::LittleEndian2Int(substr($riff_data, 40, 4)) + 44;

						if ($orignal_riff_header_size > $orignal_riff_data_size) {
							$info_avdataend -= ($orignal_riff_header_size - $orignal_riff_data_size);
							$this->fseek($info_avdataend, SEEK_SET);
							$riff_data .= $this->fread($orignal_riff_header_size - $orignal_riff_data_size);
						}

						// move the data chunk after all other chunks (if any)
						// so that the RIFF parser doesn't see EOF when trying
						// to skip over the data chunk
						$riff_data = substr($riff_data, 0, 36).substr($riff_data, 44).substr($riff_data, 36, 8);

						// Save audio info key
						$saved_info_audio = $info_audio;

						// Analyze riff_data
						$this->AnalyzeString($riff_data);

						// Restore info key
						$info_audio = $saved_info_audio;
					}
				}

				if (isset($info_riff_raw['fmt ']['wFormatTag'])) {

					switch ($info_riff_raw['fmt ']['wFormatTag']) {

						case 0x08AE: // ClearJump LiteWave
							$info_audio['bitrate_mode'] = 'vbr';
							$info_audio_dataformat      = 'litewave';

							//typedef struct tagSLwFormat {
							//  WORD    m_wCompFormat;     // low byte defines compression method, high byte is compression flags
							//  DWORD   m_dwScale;         // scale factor for lossy compression
							//  DWORD   m_dwBlockSize;     // number of samples in encoded blocks
							//  WORD    m_wQuality;        // alias for the scale factor
							//  WORD    m_wMarkDistance;   // distance between marks in bytes
							//  WORD    m_wReserved;
							//
							//  //following paramters are ignored if CF_FILESRC is not set
							//  DWORD   m_dwOrgSize;       // original file size in bytes
							//  WORD    m_bFactExists;     // indicates if 'fact' chunk exists in the original file
							//  DWORD   m_dwRiffChunkSize; // riff chunk size in the original file
							//
							//  PCMWAVEFORMAT m_OrgWf;     // original wave format
							// }SLwFormat, *PSLwFormat;

							$info_riff['litewave']['raw'] = array ();
							$info_riff_litewave           = &$info_riff['litewave'];
							$info_riff_litewave_raw       = &$info_riff_litewave['raw'];

							getid3_lib::ReadSequence('LittleEndian2Int', $info_riff_litewave_raw, $info_riff_wave['fmt '][0]['data'], 18,
								array (
									'compression_method' => 1,
									'compression_flags'  => 1,
									'm_dwScale'          => 4,
									'm_dwBlockSize'      => 4,
									'm_wQuality'         => 2,
									'm_wMarkDistance'    => 2,
									'm_wReserved'        => 2,
									'm_dwOrgSize'        => 4,
									'm_bFactExists'      => 2,
									'm_dwRiffChunkSize'  => 4
								)
							);

							//$info_riff_litewave['quality_factor'] = intval(round((2000 - $info_riff_litewave_raw['m_dwScale']) / 20));
							$info_riff_litewave['quality_factor'] = $info_riff_litewave_raw['m_wQuality'];

							$info_riff_litewave['flags']['raw_source']    = ($info_riff_litewave_raw['compression_flags'] & 0x01) ? false : true;
							$info_riff_litewave['flags']['vbr_blocksize'] = ($info_riff_litewave_raw['compression_flags'] & 0x02) ? false : true;
							$info_riff_litewave['flags']['seekpoints']    = (bool)($info_riff_litewave_raw['compression_flags'] & 0x04);

							$info_audio['lossless']        = (($info_riff_litewave_raw['m_wQuality'] == 100) ? true : false);
							$info_audio['encoder_options'] = '-q'.$info_riff_litewave['quality_factor'];
							break;
					}
				}

				if ($info_avdataend > $getid3->info['filesize']) {

					switch (@$info_audio_dataformat) {

						case 'wavpack': // WavPack
						case 'lpac':    // LPAC
						case 'ofr':     // OptimFROG
						case 'ofs':     // OptimFROG DualStream
							// lossless compressed audio formats that keep original RIFF headers - skip warning
							break;


						case 'litewave':
							if (($info_avdataend - $getid3->info['filesize']) == 1) {
								// LiteWave appears to incorrectly *not* pad actual output file
								// to nearest WORD boundary so may appear to be short by one
								// byte, in which case - skip warning
							} else {
								// Short by more than one byte, throw warning
								$getid3->warning('Probably truncated file - expecting '.$info_riff[$riff_sub_type]['data'][0]['size'].' bytes of data, only found '.($getid3->info['filesize'] - $info_avdataoffset).' (short by '.($info_riff[$riff_sub_type]['data'][0]['size'] - ($getid3->info['filesize'] - $info_avdataoffset)).' bytes)');
							}
							break;


						default:
							if ((($info_avdataend - $getid3->info['filesize']) == 1) && (($info_riff[$riff_sub_type]['data'][0]['size'] % 2) == 0) && ((($getid3->info['filesize'] - $info_avdataoffset) % 2) == 1)) {
								// output file appears to be incorrectly *not* padded to nearest WORD boundary
								// Output less severe warning
								$getid3->warning('File should probably be padded to nearest WORD boundary, but it is not (expecting '.$info_riff[$riff_sub_type]['data'][0]['size'].' bytes of data, only found '.($getid3->info['filesize'] - $info_avdataoffset).' therefore short by '.($info_riff[$riff_sub_type]['data'][0]['size'] - ($getid3->info['filesize'] - $info_avdataoffset)).' bytes)');
								$info_avdataend = $getid3->info['filesize'];
								break;

							}
							// Short by more than one byte, throw warning
							$getid3->warning('Probably truncated file - expecting '.$info_riff[$riff_sub_type]['data'][0]['size'].' bytes of data, only found '.($getid3->info['filesize'] - $info_avdataoffset).' (short by '.($info_riff[$riff_sub_type]['data'][0]['size'] - ($getid3->info['filesize'] - $info_avdataoffset)).' bytes)');
							$info_avdataend = $getid3->info['filesize'];
							break;
					}
				}

				if (!empty($getid3->info['mpeg']['audio']['LAME']['audio_bytes'])) {
					if ((($info_avdataend - $info_avdataoffset) - $getid3->info['mpeg']['audio']['LAME']['audio_bytes']) == 1) {
						$info_avdataend--;
						$getid3->warning('Extra null byte at end of MP3 data assumed to be RIFF padding and therefore ignored');
					}
				}

				if (@$info_audio_dataformat == 'ac3') {
					unset($info_audio['bits_per_sample']);
					if (!empty($getid3->info['ac3']['bitrate']) && ($getid3->info['ac3']['bitrate'] != $info_audio['bitrate'])) {
						$info_audio['bitrate'] = $getid3->info['ac3']['bitrate'];
					}
				}
				break;


			case 'AVI ':
				$info_video['bitrate_mode'] = 'vbr'; // maybe not, but probably
				$info_video['dataformat']   = 'avi';
				$getid3->info['mime_type']  = 'video/avi';

				if (isset($info_riff[$riff_sub_type]['movi']['offset'])) {
					$info_avdataoffset = $info_riff[$riff_sub_type]['movi']['offset'] + 8;
					$info_avdataend    = $info_avdataoffset + $info_riff[$riff_sub_type]['movi']['size'];
					if ($info_avdataend > $getid3->info['filesize']) {
						$getid3->warning('Probably truncated file - expecting '.$info_riff[$riff_sub_type]['movi']['size'].' bytes of data, only found '.($getid3->info['filesize'] - $info_avdataoffset).' (short by '.($info_riff[$riff_sub_type]['movi']['size'] - ($getid3->info['filesize'] - $info_avdataoffset)).' bytes)');
						$info_avdataend = $getid3->info['filesize'];
					}
				}

				if (isset($info_riff['AVI ']['hdrl']['avih'][$stream_index]['data'])) {
					$avihData = $info_riff['AVI ']['hdrl']['avih'][$stream_index]['data'];

					$info_riff_raw['avih'] = array ();
					$info_riff_raw_avih = &$info_riff_raw['avih'];

					getid3_lib::ReadSequence($this->endian_function, $info_riff_raw_avih, $avihData, 0,
						array (
							'dwMicroSecPerFrame'    => 4, // frame display rate (or 0L)
							'dwMaxBytesPerSec'      => 4, // max. transfer rate
							'dwPaddingGranularity'  => 4, // pad to multiples of this size; normally 2K.
							'dwFlags'               => 4, // the ever-present flags
							'dwTotalFrames'         => 4, // # frames in file
							'dwInitialFrames'       => 4,
							'dwStreams'             => 4,
							'dwSuggestedBufferSize' => 4,
							'dwWidth'               => 4,
							'dwHeight'              => 4,
							'dwScale'               => 4,
							'dwRate'                => 4,
							'dwStart'               => 4,
							'dwLength'              => 4
						)
					);

					$info_riff_raw_avih['flags']['hasindex']     = (bool)($info_riff_raw_avih['dwFlags'] & 0x00000010);
					$info_riff_raw_avih['flags']['mustuseindex'] = (bool)($info_riff_raw_avih['dwFlags'] & 0x00000020);
					$info_riff_raw_avih['flags']['interleaved']  = (bool)($info_riff_raw_avih['dwFlags'] & 0x00000100);
					$info_riff_raw_avih['flags']['trustcktype']  = (bool)($info_riff_raw_avih['dwFlags'] & 0x00000800);
					$info_riff_raw_avih['flags']['capturedfile'] = (bool)($info_riff_raw_avih['dwFlags'] & 0x00010000);
					$info_riff_raw_avih['flags']['copyrighted']  = (bool)($info_riff_raw_avih['dwFlags'] & 0x00020010);

					$info_riff_video[$stream_index] = array ();
					$info_riff_video_current = &$info_riff_video[$stream_index];

					if ($info_riff_raw_avih['dwWidth'] > 0) {
						$info_riff_video_current['frame_width'] = $info_riff_raw_avih['dwWidth'];
						$info_video['resolution_x']             = $info_riff_video_current['frame_width'];
					}

					if ($info_riff_raw_avih['dwHeight'] > 0) {
						$info_riff_video_current['frame_height'] = $info_riff_raw_avih['dwHeight'];
						$info_video['resolution_y']              = $info_riff_video_current['frame_height'];
					}

					if ($info_riff_raw_avih['dwTotalFrames'] > 0) {
						$info_riff_video_current['total_frames'] = $info_riff_raw_avih['dwTotalFrames'];
						$info_video['total_frames']              = $info_riff_video_current['total_frames'];
					}

					$info_riff_video_current['frame_rate'] = round(1000000 / $info_riff_raw_avih['dwMicroSecPerFrame'], 3);
					$info_video['frame_rate'] = $info_riff_video_current['frame_rate'];
				}

				if (isset($info_riff['AVI ']['hdrl']['strl']['strh'][0]['data'])) {
					if (is_array($info_riff['AVI ']['hdrl']['strl']['strh'])) {
						for ($i = 0; $i < count($info_riff['AVI ']['hdrl']['strl']['strh']); $i++) {
							if (isset($info_riff['AVI ']['hdrl']['strl']['strh'][$i]['data'])) {
								$strh_data = $info_riff['AVI ']['hdrl']['strl']['strh'][$i]['data'];
								$strh_fcc_type = substr($strh_data,  0, 4);

								if (isset($info_riff['AVI ']['hdrl']['strl']['strf'][$i]['data'])) {
									$strf_data = $info_riff['AVI ']['hdrl']['strl']['strf'][$i]['data'];

									// shortcut
									$info_riff_raw_strf_strh_fcc_type_stream_index = &$info_riff_raw['strf'][$strh_fcc_type][$stream_index];

									switch ($strh_fcc_type) {
										case 'auds':
											$info_audio['bitrate_mode'] = 'cbr';
											$info_audio_dataformat      = 'wav';
											if (isset($info_riff_audio) && is_array($info_riff_audio)) {
												$stream_index = count($info_riff_audio);
											}

											$info_riff_audio[$stream_index] = getid3_riff::RIFFparseWAVEFORMATex($strf_data);
											$info_audio['wformattag'] = $info_riff_audio[$stream_index]['raw']['wFormatTag'];

											// shortcut
											$info_audio['streams'][$stream_index] = $info_riff_audio[$stream_index];
											$info_audio_streams_currentstream = &$info_audio['streams'][$stream_index];

											if (@$info_audio_streams_currentstream['bits_per_sample'] === 0) {
												unset($info_audio_streams_currentstream['bits_per_sample']);
											}
											$info_audio_streams_currentstream['wformattag'] = $info_audio_streams_currentstream['raw']['wFormatTag'];
											unset($info_audio_streams_currentstream['raw']);

											// shortcut
											$info_riff_raw['strf'][$strh_fcc_type][$stream_index] = $info_riff_audio[$stream_index]['raw'];

											unset($info_riff_audio[$stream_index]['raw']);
											$info_audio = getid3_riff::array_merge_noclobber($info_audio, $info_riff_audio[$stream_index]);

											$info_audio['lossless'] = false;
											switch ($info_riff_raw_strf_strh_fcc_type_stream_index['wFormatTag']) {

												case 0x0001:  // PCM
													$info_audio_dataformat  = 'wav';
													$info_audio['lossless'] = true;
													break;

												case 0x0050: // MPEG Layer 2 or Layer 1
													$info_audio_dataformat = 'mp2'; // Assume Layer-2
													break;

												case 0x0055: // MPEG Layer 3
													$info_audio_dataformat = 'mp3';
													break;

												case 0x00FF: // AAC
													$info_audio_dataformat = 'aac';
													break;

												case 0x0161: // Windows Media v7 / v8 / v9
												case 0x0162: // Windows Media Professional v9
												case 0x0163: // Windows Media Lossess v9
													$info_audio_dataformat = 'wma';
													break;

												case 0x2000: // AC-3
													$info_audio_dataformat = 'ac3';
													break;

												case 0x2001: // DTS
													$info_audio_dataformat = 'dts';
													break;

												default:
													$info_audio_dataformat = 'wav';
													break;
											}
											$info_audio_streams_currentstream['dataformat']   = $info_audio_dataformat;
											$info_audio_streams_currentstream['lossless']     = $info_audio['lossless'];
											$info_audio_streams_currentstream['bitrate_mode'] = $info_audio['bitrate_mode'];
											break;


										case 'iavs':
										case 'vids':
											// shortcut
											$info_riff_raw['strh'][$i]  = array ();
											$info_riff_raw_strh_current = &$info_riff_raw['strh'][$i];

											getid3_lib::ReadSequence($this->endian_function, $info_riff_raw_strh_current, $strh_data, 0,
												array (
													'fccType'               => -4, // same as $strh_fcc_type;
													'fccHandler'            => -4,
													'dwFlags'               => 4, // Contains AVITF_* flags
													'wPriority'             => 2,
													'wLanguage'             => 2,
													'dwInitialFrames'       => 4,
													'dwScale'               => 4,
													'dwRate'                => 4,
													'dwStart'               => 4,
													'dwLength'              => 4,
													'dwSuggestedBufferSize' => 4,
													'dwQuality'             => 4,
													'dwSampleSize'          => 4,
													'rcFrame'               => 4
												)
											);

											$info_riff_video_current['codec'] = getid3_riff::RIFFfourccLookup($info_riff_raw_strh_current['fccHandler']);
											$info_video['fourcc']             = $info_riff_raw_strh_current['fccHandler'];

											if (!$info_riff_video_current['codec'] && isset($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc']) && getid3_riff::RIFFfourccLookup($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc'])) {
												$info_riff_video_current['codec'] = getid3_riff::RIFFfourccLookup($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc']);
												$info_video['fourcc']             = $info_riff_raw_strf_strh_fcc_type_stream_index['fourcc'];
											}

											$info_video['codec']              = $info_riff_video_current['codec'];
											$info_video['pixel_aspect_ratio'] = (float)1;

											switch ($info_riff_raw_strh_current['fccHandler']) {

												case 'HFYU': // Huffman Lossless Codec
												case 'IRAW': // Intel YUV Uncompressed
												case 'YUY2': // Uncompressed YUV 4:2:2
													$info_video['lossless'] = true;
													break;

												default:
													$info_video['lossless'] = false;
													break;
											}

											switch ($strh_fcc_type) {

												case 'vids':
													getid3_lib::ReadSequence($this->endian_function, $info_riff_raw_strf_strh_fcc_type_stream_index, $strf_data, 0,
														array (
															'biSize'          => 4, // number of bytes required by the BITMAPINFOHEADER structure
															'biWidth'         => 4, // width of the bitmap in pixels
															'biHeight'        => 4, // height of the bitmap in pixels. If biHeight is positive, the bitmap is a 'bottom-up' DIB and its origin is the lower left corner. If biHeight is negative, the bitmap is a 'top-down' DIB and its origin is the upper left corner
															'biPlanes'        => 2, // number of color planes on the target device. In most cases this value must be set to 1
															'biBitCount'      => 2, // Specifies the number of bits per pixels
															'fourcc'          => -4, //
															'biSizeImage'     => 4, // size of the bitmap data section of the image (the actual pixel data, excluding BITMAPINFOHEADER and RGBQUAD structures)
															'biXPelsPerMeter' => 4, // horizontal resolution, in pixels per metre, of the target device
															'biYPelsPerMeter' => 4, // vertical resolution, in pixels per metre, of the target device
															'biClrUsed'       => 4, // actual number of color indices in the color table used by the bitmap. If this value is zero, the bitmap uses the maximum number of colors corresponding to the value of the biBitCount member for the compression mode specified by biCompression
															'biClrImportant'  => 4 // number of color indices that are considered important for displaying the bitmap. If this value is zero, all colors are important
														)
													);

													$info_video['bits_per_sample'] = $info_riff_raw_strf_strh_fcc_type_stream_index['biBitCount'];

													if ($info_riff_video_current['codec'] == 'DV') {
														$info_riff_video_current['dv_type'] = 2;
													}
													break;

												case 'iavs':
													$info_riff_video_current['dv_type'] = 1;
													break;
											}
											break;

										default:
											$getid3->warning('Unhandled fccType for stream ('.$i.'): "'.$strh_fcc_type.'"');
											break;

									}
								}
							}

							if (isset($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc']) && getid3_riff::RIFFfourccLookup($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc'])) {

								$info_riff_video_current['codec'] = getid3_riff::RIFFfourccLookup($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc']);
								$info_video['codec']              = $info_riff_video_current['codec'];
								$info_video['fourcc']             = $info_riff_raw_strf_strh_fcc_type_stream_index['fourcc'];

								switch ($info_riff_raw_strf_strh_fcc_type_stream_index['fourcc']) {

									case 'HFYU': // Huffman Lossless Codec
									case 'IRAW': // Intel YUV Uncompressed
									case 'YUY2': // Uncompressed YUV 4:2:2
										$info_video['lossless']        = true;
										$info_video['bits_per_sample'] = 24;
										break;

									default:
										$info_video['lossless']        = false;
										$info_video['bits_per_sample'] = 24;
										break;
								}

							}
						}
					}
				}
				break;


			case 'CDDA':
				$info_audio['bitrate_mode'] = 'cbr';
				$info_audio_dataformat      = 'cda';
				$info_audio['lossless']     = true;
				unset($getid3->info['mime_type']);

				$info_avdataoffset = 44;

				if (isset($info_riff['CDDA']['fmt '][0]['data'])) {

					$info_riff_cdda_fmt_0 = &$info_riff['CDDA']['fmt '][0];

					getid3_lib::ReadSequence($this->endian_function, $info_riff_cdda_fmt_0, $info_riff_cdda_fmt_0['data'], 0,
						array (
							'unknown1'           => 2,
							'track_num'          => 2,
							'disc_id'            => 4,
							'start_offset_frame' => 4,
							'playtime_frames'    => 4,
							'unknown6'           => 4,
							'unknown7'           => 4
						)
					);

					$info_riff_cdda_fmt_0['start_offset_seconds'] = (float)$info_riff_cdda_fmt_0['start_offset_frame'] / 75;
					$info_riff_cdda_fmt_0['playtime_seconds']     = (float)$info_riff_cdda_fmt_0['playtime_frames'] / 75;
					$getid3->info['comments']['track']            = $info_riff_cdda_fmt_0['track_num'];
					$getid3->info['playtime_seconds']             = $info_riff_cdda_fmt_0['playtime_seconds'];

					// hardcoded data for CD-audio
					$info_audio['sample_rate']     = 44100;
					$info_audio['channels']        = 2;
					$info_audio['bits_per_sample'] = 16;
					$info_audio['bitrate']         = $info_audio['sample_rate'] * $info_audio['channels'] * $info_audio['bits_per_sample'];
					$info_audio['bitrate_mode']    = 'cbr';
				}
				break;


			case 'AIFF':
			case 'AIFC':
				$info_audio['bitrate_mode'] = 'cbr';
				$info_audio_dataformat      = 'aiff';
				$info_audio['lossless']     = true;
				$getid3->info['mime_type']      = 'audio/x-aiff';

				if (isset($info_riff[$riff_sub_type]['SSND'][0]['offset'])) {
					$info_avdataoffset = $info_riff[$riff_sub_type]['SSND'][0]['offset'] + 8;
					$info_avdataend    = $info_avdataoffset + $info_riff[$riff_sub_type]['SSND'][0]['size'];
					if ($info_avdataend > $getid3->info['filesize']) {
						if (($info_avdataend == ($getid3->info['filesize'] + 1)) && (($getid3->info['filesize'] % 2) == 1)) {
							// structures rounded to 2-byte boundary, but dumb encoders
							// forget to pad end of file to make this actually work
						} else {
							$getid3->warning('Probable truncated AIFF file: expecting '.$info_riff[$riff_sub_type]['SSND'][0]['size'].' bytes of audio data, only '.($getid3->info['filesize'] - $info_avdataoffset).' bytes found');
						}
						$info_avdataend = $getid3->info['filesize'];
					}
				}

				if (isset($info_riff[$riff_sub_type]['COMM'][0]['data'])) {

					// shortcut
					$info_riff_RIFFsubtype_COMM_0_data = &$info_riff[$riff_sub_type]['COMM'][0]['data'];

					$info_riff_audio['channels']         = getid3_lib::BigEndianSyncSafe2Int(substr($info_riff_RIFFsubtype_COMM_0_data,  0,  2));
					$info_riff_audio['total_samples']    = getid3_lib::BigEndian2Int(        substr($info_riff_RIFFsubtype_COMM_0_data,  2,  4));
					$info_riff_audio['bits_per_sample']  = getid3_lib::BigEndianSyncSafe2Int(substr($info_riff_RIFFsubtype_COMM_0_data,  6,  2));
					$info_riff_audio['sample_rate']      = (int)getid3_riff::BigEndian2Float(substr($info_riff_RIFFsubtype_COMM_0_data,  8, 10));

					if ($info_riff[$riff_sub_type]['COMM'][0]['size'] > 18) {
						$info_riff_audio['codec_fourcc'] =                           substr($info_riff_RIFFsubtype_COMM_0_data, 18,  4);
						$codec_name_size                 = getid3_lib::BigEndian2Int(substr($info_riff_RIFFsubtype_COMM_0_data, 22,  1));
						$info_riff_audio['codec_name']   =                           substr($info_riff_RIFFsubtype_COMM_0_data, 23,  $codec_name_size);

						switch ($info_riff_audio['codec_name']) {

							case 'NONE':
								$info_audio['codec']    = 'Pulse Code Modulation (PCM)';
								$info_audio['lossless'] = true;
								break;

							case '':
								switch ($info_riff_audio['codec_fourcc']) {

									// http://developer.apple.com/qa/snd/snd07.html
									case 'sowt':
										$info_riff_audio['codec_name'] = 'Two\'s Compliment Little-Endian PCM';
										$info_audio['lossless'] = true;
										break;

									case 'twos':
										$info_riff_audio['codec_name'] = 'Two\'s Compliment Big-Endian PCM';
										$info_audio['lossless'] = true;
										break;

									default:
										break;
								}
								break;

							default:
								$info_audio['codec']    = $info_riff_audio['codec_name'];
								$info_audio['lossless'] = false;
								break;
						}
					}

					$info_audio['channels'] = $info_riff_audio['channels'];

					if ($info_riff_audio['bits_per_sample'] > 0) {
						$info_audio['bits_per_sample'] = $info_riff_audio['bits_per_sample'];
					}

					$info_audio['sample_rate']        = $info_riff_audio['sample_rate'];
					$getid3->info['playtime_seconds'] = $info_riff_audio['total_samples'] / $info_audio['sample_rate'];
				}

				if (isset($info_riff[$riff_sub_type]['COMT'])) {

					$comment_count = getid3_lib::BigEndian2Int(substr($info_riff[$riff_sub_type]['COMT'][0]['data'], 0, 2));
					$offset = 2;

					for ($i = 0; $i < $comment_count; $i++) {

						$getid3->info['comments_raw'][$i]['timestamp'] = getid3_lib::BigEndian2Int(        substr($info_riff[$riff_sub_type]['COMT'][0]['data'], $offset, 4));
						$offset += 4;

						$getid3->info['comments_raw'][$i]['marker_id'] = getid3_lib::BigEndianSyncSafe2Int(substr($info_riff[$riff_sub_type]['COMT'][0]['data'], $offset, 2));
						$offset += 2;

						$comment_length                                = getid3_lib::BigEndian2Int(        substr($info_riff[$riff_sub_type]['COMT'][0]['data'], $offset, 2));
						$offset += 2;

						$getid3->info['comments_raw'][$i]['comment']   =                                   substr($info_riff[$riff_sub_type]['COMT'][0]['data'], $offset, $comment_length);
						$offset += $comment_length;

						$getid3->info['comments_raw'][$i]['timestamp_unix'] = getid3_riff::DateMac2Unix($getid3->info['comments_raw'][$i]['timestamp']);
						$info_riff['comments']['comment'][] = $getid3->info['comments_raw'][$i]['comment'];
					}
				}

				foreach (array ('NAME'=>'title', 'author'=>'artist', '(c) '=>'copyright', 'ANNO'=>'comment') as $key => $value) {
					if (isset($info_riff[$riff_sub_type][$key][0]['data'])) {
						$info_riff['comments'][$value][] = $info_riff[$riff_sub_type][$key][0]['data'];
					}
				}
				break;


			case '8SVX':
				$info_audio['bitrate_mode']    = 'cbr';
				$info_audio_dataformat         = '8svx';
				$info_audio['bits_per_sample'] = 8;
				$info_audio['channels']        = 1; // overridden below, if need be
				$getid3->info['mime_type']     = 'audio/x-aiff';

				if (isset($info_riff[$riff_sub_type]['BODY'][0]['offset'])) {
					$info_avdataoffset = $info_riff[$riff_sub_type]['BODY'][0]['offset'] + 8;
					$info_avdataend    = $info_avdataoffset + $info_riff[$riff_sub_type]['BODY'][0]['size'];
					if ($info_avdataend > $getid3->info['filesize']) {
						$getid3->warning('Probable truncated AIFF file: expecting '.$info_riff[$riff_sub_type]['BODY'][0]['size'].' bytes of audio data, only '.($getid3->info['filesize'] - $info_avdataoffset).' bytes found');
					}
				}

				if (isset($info_riff[$riff_sub_type]['VHDR'][0]['offset'])) {
					// shortcut
					$info_riff_riff_sub_type_vhdr_0 = &$info_riff[$riff_sub_type]['VHDR'][0];

					getid3_lib::ReadSequence('BigEndian2Int', $info_riff_riff_sub_type_vhdr_0, $info_riff_riff_sub_type_vhdr_0['data'], 0,
						array (
							'oneShotHiSamples'  => 4,
							'repeatHiSamples'   => 4,
							'samplesPerHiCycle' => 4,
							'samplesPerSec'     => 2,
							'ctOctave'          => 1,
							'sCompression'      => 1,
							'Volume'            => -4
						)
					);

					$info_riff_riff_sub_type_vhdr_0['Volume'] = getid3_riff::FixedPoint16_16($info_riff_riff_sub_type_vhdr_0['Volume']);

					$info_audio['sample_rate'] = $info_riff_riff_sub_type_vhdr_0['samplesPerSec'];

					switch ($info_riff_riff_sub_type_vhdr_0['sCompression']) {
						case 0:
							$info_audio['codec']    = 'Pulse Code Modulation (PCM)';
							$info_audio['lossless'] = true;
							$actual_bits_per_sample = 8;
							break;

						case 1:
							$info_audio['codec']    = 'Fibonacci-delta encoding';
							$info_audio['lossless'] = false;
							$actual_bits_per_sample = 4;
							break;

						default:
							$getid3->warning('Unexpected sCompression value in 8SVX.VHDR chunk - expecting 0 or 1, found "'.sCompression.'"');
							break;
					}
				}

				if (isset($info_riff[$riff_sub_type]['CHAN'][0]['data'])) {
					$ChannelsIndex = getid3_lib::BigEndian2Int(substr($info_riff[$riff_sub_type]['CHAN'][0]['data'], 0, 4));
					switch ($ChannelsIndex) {
						case 6: // Stereo
							$info_audio['channels'] = 2;
							break;

						case 2: // Left channel only
						case 4: // Right channel only
							$info_audio['channels'] = 1;
							break;

						default:
							$getid3->warning('Unexpected value in 8SVX.CHAN chunk - expecting 2 or 4 or 6, found "'.$ChannelsIndex.'"');
							break;
					}

				}

				foreach (array ('NAME'=>'title', 'author'=>'artist', '(c) '=>'copyright', 'ANNO'=>'comment') as $key => $value) {
					if (isset($info_riff[$riff_sub_type][$key][0]['data'])) {
						$info_riff['comments'][$value][] = $info_riff[$riff_sub_type][$key][0]['data'];
					}
				}

				$info_audio['bitrate'] = $info_audio['sample_rate'] * $actual_bits_per_sample * $info_audio['channels'];
				if (!empty($info_audio['bitrate'])) {
					$getid3->info['playtime_seconds'] = ($info_avdataend - $info_avdataoffset) / ($info_audio['bitrate'] / 8);
				}
				break;


			case 'CDXA':

				$getid3->info['mime_type'] = 'video/mpeg';
				if (!empty($info_riff['CDXA']['data'][0]['size'])) {
					$GETID3_ERRORARRAY = &$getid3->info['warning'];

					if (!$getid3->include_module_optional('audio-video.mpeg')) {
						$getid3->warning('MPEG skipped because mpeg module is missing.');
					}

					else {

						// Clone getid3 - messing with offsets - better safe than sorry
						$clone = clone $getid3;

						// Analyse
						$mpeg = new getid3_mpeg($clone);
						$mpeg->Analyze();

						// Import from clone and destroy
						$getid3->info['audio']   = $clone->info['audio'];
						$getid3->info['video']   = $clone->info['video'];
						$getid3->info['mpeg']    = $clone->info['mpeg'];
						$getid3->info['warning'] = $clone->info['warning'];

						unset($clone);
					}
				}

				break;


			default:
				throw new getid3_exception('Unknown RIFF type: expecting one of (WAVE|RMP3|AVI |CDDA|AIFF|AIFC|8SVX|CDXA), found "'.$riff_sub_type.'" instead');
		}


		if (@$info_riff_raw['fmt ']['wFormatTag'] == 1) {

			// http://www.mega-nerd.com/erikd/Blog/Windiots/dts.html
			$this->fseek($getid3->info['avdataoffset'], SEEK_SET);
			$bytes4 = $this->fread(4);

			// DTSWAV
			if (preg_match('/^\xFF\x1F\x00\xE8/s', $bytes4)) {
				$info_audio_dataformat = 'dts';
			}

			// DTS, but this probably shouldn't happen
			elseif (preg_match('/^\x7F\xFF\x80\x01/s', $bytes4)) {
				$info_audio_dataformat = 'dts';
			}
		}

		if (@is_array($info_riff_wave['DISP'])) {
			$info_riff['comments']['title'][] = trim(substr($info_riff_wave['DISP'][count($info_riff_wave['DISP']) - 1]['data'], 4));
		}

		if (@is_array($info_riff_wave['INFO'])) {
			getid3_riff::RIFFCommentsParse($info_riff_wave['INFO'], $info_riff['comments']);
		}

		if (isset($info_riff_wave['INFO']) && is_array($info_riff_wave['INFO'])) {

			foreach (array ('IARL' => 'archivallocation', 'IART' => 'artist', 'ICDS' => 'costumedesigner', 'ICMS' => 'commissionedby', 'ICMT' => 'comment', 'ICNT' => 'country', 'ICOP' => 'copyright', 'ICRD' => 'creationdate', 'IDIM' => 'dimensions', 'IDIT' => 'digitizationdate', 'IDPI' => 'resolution', 'IDST' => 'distributor', 'IEDT' => 'editor', 'IENG' => 'engineers', 'IFRM' => 'accountofparts', 'IGNR' => 'genre', 'IKEY' => 'keywords', 'ILGT' => 'lightness', 'ILNG' => 'language', 'IMED' => 'orignalmedium', 'IMUS' => 'composer', 'INAM' => 'title', 'IPDS' => 'productiondesigner', 'IPLT' => 'palette', 'IPRD' => 'product', 'IPRO' => 'producer', 'IPRT' => 'part', 'IRTD' => 'rating', 'ISBJ' => 'subject', 'ISFT' => 'software', 'ISGN' => 'secondarygenre', 'ISHP' => 'sharpness', 'ISRC' => 'sourcesupplier', 'ISRF' => 'digitizationsource', 'ISTD' => 'productionstudio', 'ISTR' => 'starring', 'ITCH' => 'encoded_by', 'IWEB' => 'url', 'IWRI' => 'writer') as $key => $value) {
				if (isset($info_riff_wave['INFO'][$key])) {
					foreach ($info_riff_wave['INFO'][$key] as $comment_id => $comment_data) {
						if (trim($comment_data['data']) != '') {
							$info_riff['comments'][$value][] = trim($comment_data['data']);
						}
					}
				}
			}
		}

		if (empty($info_audio['encoder']) && !empty($getid3->info['mpeg']['audio']['LAME']['short_version'])) {
			$info_audio['encoder'] = $getid3->info['mpeg']['audio']['LAME']['short_version'];
		}

		if (!isset($getid3->info['playtime_seconds'])) {
			$getid3->info['playtime_seconds'] = 0;
		}

		if (isset($info_riff_raw['avih']['dwTotalFrames']) && isset($info_riff_raw['avih']['dwMicroSecPerFrame'])) {
			$getid3->info['playtime_seconds'] = $info_riff_raw['avih']['dwTotalFrames'] * ($info_riff_raw['avih']['dwMicroSecPerFrame'] / 1000000);
		}

		if ($getid3->info['playtime_seconds'] > 0) {
			if (isset($info_riff_audio) && isset($info_riff_video)) {

				if (!isset($getid3->info['bitrate'])) {
					$getid3->info['bitrate'] = ((($info_avdataend - $info_avdataoffset) / $getid3->info['playtime_seconds']) * 8);
				}

			} elseif (isset($info_riff_audio) && !isset($info_riff_video)) {

				if (!isset($info_audio['bitrate'])) {
					$info_audio['bitrate'] = ((($info_avdataend - $info_avdataoffset) / $getid3->info['playtime_seconds']) * 8);
				}

			} elseif (!isset($info_riff_audio) && isset($info_riff_video)) {

				if (!isset($info_video['bitrate'])) {
					$info_video['bitrate'] = ((($info_avdataend - $info_avdataoffset) / $getid3->info['playtime_seconds']) * 8);
				}

			}
		}


		if (isset($info_riff_video) && isset($info_audio['bitrate']) && ($info_audio['bitrate'] > 0) && ($getid3->info['playtime_seconds'] > 0)) {

			$getid3->info['bitrate'] = ((($info_avdataend - $info_avdataoffset) / $getid3->info['playtime_seconds']) * 8);
			$info_audio['bitrate'] = 0;
			$info_video['bitrate'] = $getid3->info['bitrate'];
			foreach ($info_riff_audio as $channelnumber => $audioinfoarray) {
				$info_video['bitrate'] -= $audioinfoarray['bitrate'];
				$info_audio['bitrate'] += $audioinfoarray['bitrate'];
			}
			if ($info_video['bitrate'] <= 0) {
				unset($info_video['bitrate']);
			}
			if ($info_audio['bitrate'] <= 0) {
				unset($info_audio['bitrate']);
			}
		}

		if (isset($getid3->info['mpeg']['audio'])) {
			$info_audio_dataformat      = 'mp'.$getid3->info['mpeg']['audio']['layer'];
			$info_audio['sample_rate']  = $getid3->info['mpeg']['audio']['sample_rate'];
			$info_audio['channels']     = $getid3->info['mpeg']['audio']['channels'];
			$info_audio['bitrate']      = $getid3->info['mpeg']['audio']['bitrate'];
			$info_audio['bitrate_mode'] = strtolower($getid3->info['mpeg']['audio']['bitrate_mode']);

			if (!empty($getid3->info['mpeg']['audio']['codec'])) {
				$info_audio['codec'] = $getid3->info['mpeg']['audio']['codec'].' '.$info_audio['codec'];
			}

			if (!empty($info_audio['streams'])) {
				foreach ($info_audio['streams'] as $streamnumber => $streamdata) {
					if ($streamdata['dataformat'] == $info_audio_dataformat) {
						$info_audio['streams'][$streamnumber]['sample_rate']  = $info_audio['sample_rate'];
						$info_audio['streams'][$streamnumber]['channels']     = $info_audio['channels'];
						$info_audio['streams'][$streamnumber]['bitrate']      = $info_audio['bitrate'];
						$info_audio['streams'][$streamnumber]['bitrate_mode'] = $info_audio['bitrate_mode'];
						$info_audio['streams'][$streamnumber]['codec']        = $info_audio['codec'];
					}
				}
			}
			$info_audio['encoder_options'] = getid3_mp3::GuessEncoderOptions($getid3->info);
		}


		if (!empty($info_riff_raw['fmt ']['wBitsPerSample']) && ($info_riff_raw['fmt ']['wBitsPerSample'] > 0)) {
			switch ($info_audio_dataformat) {
				case 'ac3':
					// ignore bits_per_sample
					break;

				default:
					$info_audio['bits_per_sample'] = $info_riff_raw['fmt ']['wBitsPerSample'];
					break;
			}
		}


		if (empty($info_riff_raw)) {
			unset($info_riff['raw']);
		}
		if (empty($info_riff_audio)) {
			unset($info_riff['audio']);
		}
		if (empty($info_riff_video)) {
			unset($info_riff['video']);
		}
		if (empty($info_audio_dataformat)) {
			unset($info_audio['dataformat']);
		}
		if (empty($getid3->info['audio'])) {
			unset($getid3->info['audio']);
		}
		if (empty($info_video)) {
			unset($getid3->info['video']);
		}

		return true;
	}



	public function ParseRIFF($start_offset, $max_offset) {

		$getid3 = $this->getid3;

		$info   = &$getid3->info;

		$endian_function = $this->endian_function;

		$max_offset = min($max_offset, $info['avdataend']);

		$riff_chunk = false;

		$this->fseek($start_offset, SEEK_SET);

		while ($this->ftell() < $max_offset) {

			$chunk_name = $this->fread(4);

			if (strlen($chunk_name) < 4) {
				throw new getid3_exception('Expecting chunk name at offset '.($this->ftell() - 4).' but found nothing. Aborting RIFF parsing.');
			}

			$chunk_size = getid3_lib::$endian_function($this->fread(4));

			if ($chunk_size == 0) {
				continue;
				throw new getid3_exception('Chunk size at offset '.($this->ftell() - 4).' is zero. Aborting RIFF parsing.');
			}

			if (($chunk_size % 2) != 0) {
				// all structures are packed on word boundaries
				$chunk_size++;
			}

			switch ($chunk_name) {

				case 'LIST':
					$list_name = $this->fread(4);

					switch ($list_name) {

						case 'movi':
						case 'rec ':
							$riff_chunk[$list_name]['offset'] = $this->ftell() - 4;
							$riff_chunk[$list_name]['size']   = $chunk_size;

							static $parsed_audio_stream = false;

							if (!$parsed_audio_stream) {
								$where_we_were = $this->ftell();
								$audio_chunk_header = $this->fread(12);
								$audio_chunk_stream_num  =                             substr($audio_chunk_header, 0, 2);
								$audio_chunk_stream_type =                             substr($audio_chunk_header, 2, 2);
								$audio_chunk_size       = getid3_lib::LittleEndian2Int(substr($audio_chunk_header, 4, 4));

								if ($audio_chunk_stream_type == 'wb') {
									$first_four_bytes = substr($audio_chunk_header, 8, 4);


									//// MPEG

									if (preg_match('/^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\xEB]/s', $first_four_bytes)) {

										if (!$getid3->include_module_optional('audio.mp3')) {
											$getid3->warning('MP3 skipped because mp3 module is missing.');
										}

										elseif (getid3_mp3::MPEGaudioHeaderBytesValid($first_four_bytes)) {

											// Clone getid3 - messing with offsets - better safe than sorry
											$clone = clone $getid3;
											$clone->info['avdataoffset'] = $this->ftell() - 4;
											$clone->info['avdataend']    = $this->ftell() + $audio_chunk_size;

											$mp3 = new getid3_mp3($clone);
											$mp3->AnalyzeMPEGaudioInfo();

											// Import from clone and destroy
											if (isset($clone->info['mpeg']['audio'])) {

												$info['mpeg']['audio'] = $clone->info['mpeg']['audio'];

												$info['audio']['dataformat']   = 'mp'.$info['mpeg']['audio']['layer'];
												$info['audio']['sample_rate']  = $info['mpeg']['audio']['sample_rate'];
												$info['audio']['channels']     = $info['mpeg']['audio']['channels'];
												$info['audio']['bitrate']      = $info['mpeg']['audio']['bitrate'];
												$info['audio']['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
												$info['bitrate']               = $info['audio']['bitrate'];

												$getid3->warning($clone->warnings());
												unset($clone);
											}
										}
									}

									//// AC3-WAVE

									elseif (preg_match('/^\x0B\x77/s', $first_four_bytes)) {

										if (!$getid3->include_module_optional('audio.ac3')) {
											$getid3->warning('AC3 skipped because ac3 module is missing.');
										}

										else {

											// Clone getid3 - messing with offsets - better safe than sorry
											$clone = clone $getid3;
											$clone->info['avdataoffset'] = $this->ftell() - 4;
											$clone->info['avdataend']    = $this->ftell() + $audio_chunk_size;

											// Analyze clone by fp
											$ac3 = new getid3_ac3($clone);
											$ac3->Analyze();

											// Import from clone and destroy
											$info['audio'] = $clone->info['audio'];
											$info['ac3']   = $clone->info['ac3'];
											$getid3->warning($clone->warnings());
											unset($clone);
										}
									}
								}

								$parsed_audio_stream = true;
								$this->fseek($where_we_were, SEEK_SET);

							}
							$this->fseek($chunk_size - 4, SEEK_CUR);
							break;

						default:
							if (!isset($riff_chunk[$list_name])) {
								$riff_chunk[$list_name] = array ();
							}
							$list_chunk_parent    = $list_name;
							$list_chunk_max_offset = $this->ftell() - 4 + $chunk_size;
							if ($parsed_chunk = $this->ParseRIFF($this->ftell(), $this->ftell() + $chunk_size - 4)) {
								$riff_chunk[$list_name] = array_merge_recursive($riff_chunk[$list_name], $parsed_chunk);
							}
							break;
					}
					break;


				default:

					$this_index = 0;
					if (isset($riff_chunk[$chunk_name]) && is_array($riff_chunk[$chunk_name])) {
						$this_index = count($riff_chunk[$chunk_name]);
					}
					$riff_chunk[$chunk_name][$this_index]['offset'] = $this->ftell() - 8;
					$riff_chunk[$chunk_name][$this_index]['size']   = $chunk_size;
					switch ($chunk_name) {
						case 'data':
							$info['avdataoffset'] = $this->ftell();
							$info['avdataend']    = $info['avdataoffset'] + $chunk_size;

							$riff_data_chunk_contents_test = $this->fread(36);


							//// This is probably MP3 data

							if ((strlen($riff_data_chunk_contents_test) > 0) && preg_match('/^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\xEB]/s', substr($riff_data_chunk_contents_test, 0, 4))) {

								try {

									if (!$getid3->include_module_optional('audio.mp3')) {
										$getid3->warning('MP3 skipped because mp3 module is missing.');
									}


									// Clone getid3 - messing with offsets - better safe than sorry
									$clone = clone $getid3;

									if (getid3_mp3::MPEGaudioHeaderBytesValid(substr($riff_data_chunk_contents_test, 0, 4))) {

										$mp3 = new getid3_mp3($clone);
										$mp3->AnalyzeMPEGaudioInfo();

										// Import from clone and destroy
										if (isset($clone->info['mpeg']['audio'])) {

											$info['mpeg']['audio'] = $clone->info['mpeg']['audio'];

											$info['audio']['sample_rate']  = $info['mpeg']['audio']['sample_rate'];
											$info['audio']['channels']     = $info['mpeg']['audio']['channels'];
											$info['audio']['bitrate']      = $info['mpeg']['audio']['bitrate'];
											$info['audio']['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
											$info['bitrate']               = $info['audio']['bitrate'];

											$getid3->warning($clone->warnings());
											unset($clone);
										}
									}
								}
								catch (Exception $e) {
									// do nothing - not MP3 data
								}
							}


							//// This is probably AC-3 data

							elseif ((strlen($riff_data_chunk_contents_test) > 0) && (substr($riff_data_chunk_contents_test, 0, 2) == "\x0B\x77")) {

								if (!$getid3->include_module_optional('audio.ac3')) {
									$getid3->warning('AC3 skipped because ac3 module is missing.');
								}

								else {

									// Clone getid3 - messing with offsets - better safe than sorry
									$clone = clone $getid3;
									$clone->info['avdataoffset'] = $riff_chunk[$chunk_name][$this_index]['offset'];
									$clone->info['avdataend']    = $clone->info['avdataoffset'] + $riff_chunk[$chunk_name][$this_index]['size'];

									// Analyze clone by fp
									$ac3 = new getid3_ac3($clone);
									$ac3->Analyze();

									// Import from clone and destroy
									$info['audio'] = $clone->info['audio'];
									$info['ac3']   = $clone->info['ac3'];
									$getid3->warning($clone->warnings());
									unset($clone);
								}
							}


							// Dolby Digital WAV
							// AC-3 content, but not encoded in same format as normal AC-3 file
							// For one thing, byte order is swapped

							elseif ((strlen($riff_data_chunk_contents_test) > 0) && (substr($riff_data_chunk_contents_test, 8, 2) == "\x77\x0B")) {

								if (!$getid3->include_module_optional('audio.ac3')) {
									$getid3->warning('AC3 skipped because ac3 module is missing.');
								}

								else {

									// Extract ac3 data to string
									$ac3_data = '';
									for ($i = 0; $i < 28; $i += 2) {
										// swap byte order
										$ac3_data .= substr($riff_data_chunk_contents_test, 8 + $i + 1, 1);
										$ac3_data .= substr($riff_data_chunk_contents_test, 8 + $i + 0, 1);
									}

									// Clone getid3 - messing with offsets - better safe than sorry
									$clone = clone $getid3;
									$clone->info['avdataoffset'] = 0;
									$clone->info['avdataend']    = 20;

									// Analyse clone by string
									$ac3 = new getid3_ac3($clone);
									$ac3->AnalyzeString($ac3_data);

									// Import from clone and destroy
									$info['audio'] = $clone->info['audio'];
									$info['ac3']   = $clone->info['ac3'];
									$getid3->warning($clone->warnings());
									unset($clone);
								}
							}


							if ((strlen($riff_data_chunk_contents_test) > 0) && (substr($riff_data_chunk_contents_test, 0, 4) == 'wvpk')) {

								// This is WavPack data
								$info['wavpack']['offset'] = $riff_chunk[$chunk_name][$this_index]['offset'];
								$info['wavpack']['size']   = getid3_lib::LittleEndian2Int(substr($riff_data_chunk_contents_test, 4, 4));
								$this->RIFFparseWavPackHeader(substr($riff_data_chunk_contents_test, 8, 28));

							} else {

								// This is some other kind of data (quite possibly just PCM)
								// do nothing special, just skip it

							}
							$this->fseek($riff_chunk[$chunk_name][$this_index]['offset'] + 8 + $chunk_size, SEEK_SET);
							break;

						case 'bext':
						case 'cart':
						case 'fmt ':
						case 'MEXT':
						case 'DISP':
							// always read data in
							$riff_chunk[$chunk_name][$this_index]['data'] = $this->fread($chunk_size);
							break;

						default:
							if (!empty($list_chunk_parent) && (($riff_chunk[$chunk_name][$this_index]['offset'] + $riff_chunk[$chunk_name][$this_index]['size']) <= $list_chunk_max_offset)) {
								$riff_chunk[$list_chunk_parent][$chunk_name][$this_index]['offset'] = $riff_chunk[$chunk_name][$this_index]['offset'];
								$riff_chunk[$list_chunk_parent][$chunk_name][$this_index]['size']   = $riff_chunk[$chunk_name][$this_index]['size'];
								unset($riff_chunk[$chunk_name][$this_index]['offset']);
								unset($riff_chunk[$chunk_name][$this_index]['size']);
								if (isset($riff_chunk[$chunk_name][$this_index]) && empty($riff_chunk[$chunk_name][$this_index])) {
									unset($riff_chunk[$chunk_name][$this_index]);
								}
								if (isset($riff_chunk[$chunk_name]) && empty($riff_chunk[$chunk_name])) {
									unset($riff_chunk[$chunk_name]);
								}
								$riff_chunk[$list_chunk_parent][$chunk_name][$this_index]['data'] = $this->fread($chunk_size);
							} elseif ($chunk_size < 2048) {
								// only read data in if smaller than 2kB
								$riff_chunk[$chunk_name][$this_index]['data'] = $this->fread($chunk_size);
							} else {
								$this->fseek($chunk_size, SEEK_CUR);
							}
							break;
					}
					break;

			}

		}

		return $riff_chunk;
	}



	private function RIFFparseWavPackHeader($wavpack3_chunk_data) {

		// typedef struct {
		//     char ckID [4];
		//     long ckSize;
		//     short version;
		//     short bits;                // added for version 2.00
		//     short flags, shift;        // added for version 3.00
		//     long total_samples, crc, crc2;
		//     char extension [4], extra_bc, extras [3];
		// } WavpackHeader;

		$this->getid3->info['wavpack'] = array ();
		$info_wavpack = &$this->getid3->info['wavpack'];

		$info_wavpack['version'] = getid3_lib::LittleEndian2Int(substr($wavpack3_chunk_data,  0, 2));

		if ($info_wavpack['version'] >= 2) {
			$info_wavpack['bits'] = getid3_lib::LittleEndian2Int(substr($wavpack3_chunk_data,  2, 2));
		}

		if ($info_wavpack['version'] >= 3) {

			getid3_lib::ReadSequence('LittleEndian2Int', $info_wavpack, $wavpack3_chunk_data,  4,
				array (
					'flags_raw'     => 2,
					'shift'         => 2,
					'total_samples' => 4,
					'crc1'          => 4,
					'crc2'          => 4,
					'extension'     => -4,
					'extra_bc'      => 1
				)
			);

			for ($i = 0; $i < 3; $i++) {
				$info_wavpack['extras'][] = getid3_lib::LittleEndian2Int($wavpack3_chunk_data{25 + $i});
			}

			$info_wavpack['flags'] = array ();
			$info_wavpack_flags = &$info_wavpack['flags'];

			$info_wavpack_flags['mono']                 = (bool)($info_wavpack['flags_raw'] & 0x000001);
			$info_wavpack_flags['fast_mode']            = (bool)($info_wavpack['flags_raw'] & 0x000002);
			$info_wavpack_flags['raw_mode']             = (bool)($info_wavpack['flags_raw'] & 0x000004);
			$info_wavpack_flags['calc_noise']           = (bool)($info_wavpack['flags_raw'] & 0x000008);
			$info_wavpack_flags['high_quality']         = (bool)($info_wavpack['flags_raw'] & 0x000010);
			$info_wavpack_flags['3_byte_samples']       = (bool)($info_wavpack['flags_raw'] & 0x000020);
			$info_wavpack_flags['over_20_bits']         = (bool)($info_wavpack['flags_raw'] & 0x000040);
			$info_wavpack_flags['use_wvc']              = (bool)($info_wavpack['flags_raw'] & 0x000080);
			$info_wavpack_flags['noiseshaping']         = (bool)($info_wavpack['flags_raw'] & 0x000100);
			$info_wavpack_flags['very_fast_mode']       = (bool)($info_wavpack['flags_raw'] & 0x000200);
			$info_wavpack_flags['new_high_quality']     = (bool)($info_wavpack['flags_raw'] & 0x000400);
			$info_wavpack_flags['cancel_extreme']       = (bool)($info_wavpack['flags_raw'] & 0x000800);
			$info_wavpack_flags['cross_decorrelation']  = (bool)($info_wavpack['flags_raw'] & 0x001000);
			$info_wavpack_flags['new_decorrelation']    = (bool)($info_wavpack['flags_raw'] & 0x002000);
			$info_wavpack_flags['joint_stereo']         = (bool)($info_wavpack['flags_raw'] & 0x004000);
			$info_wavpack_flags['extra_decorrelation']  = (bool)($info_wavpack['flags_raw'] & 0x008000);
			$info_wavpack_flags['override_noiseshape']  = (bool)($info_wavpack['flags_raw'] & 0x010000);
			$info_wavpack_flags['override_jointstereo'] = (bool)($info_wavpack['flags_raw'] & 0x020000);
			$info_wavpack_flags['copy_source_filetime'] = (bool)($info_wavpack['flags_raw'] & 0x040000);
			$info_wavpack_flags['create_exe']           = (bool)($info_wavpack['flags_raw'] & 0x080000);
		}

		return true;
	}



	public function AnalyzeString(&$string) {

		// Rewrite header_size in header
		$new_header_size = getid3_lib::LittleEndian2String(strlen($string), 4);
		for ($i = 0; $i < 4; $i++) {
			$string{$i + 4} = $new_header_size{$i};
		}

		return parent::AnalyzeString($string);
	}



	public static function RIFFparseWAVEFORMATex($wave_format_ex_data) {

		$wave_format_ex['raw'] = array ();
		$wave_format_ex_raw    = &$wave_format_ex['raw'];

		getid3_lib::ReadSequence('LittleEndian2Int', $wave_format_ex_raw, $wave_format_ex_data, 0,
			array (
				'wFormatTag'      => 2,
				'nChannels'       => 2,
				'nSamplesPerSec'  => 4,
				'nAvgBytesPerSec' => 4,
				'nBlockAlign'     => 2,
				'wBitsPerSample'  => 2
			)
		);

		if (strlen($wave_format_ex_data) > 16) {
			$wave_format_ex_raw['cbSize'] = getid3_lib::LittleEndian2Int(substr($wave_format_ex_data, 16, 2));
		}

		$wave_format_ex['codec']           = getid3_riff::RIFFwFormatTagLookup($wave_format_ex_raw['wFormatTag']);
		$wave_format_ex['channels']        = $wave_format_ex_raw['nChannels'];
		$wave_format_ex['sample_rate']     = $wave_format_ex_raw['nSamplesPerSec'];
		$wave_format_ex['bitrate']         = $wave_format_ex_raw['nAvgBytesPerSec'] * 8;
		if (@$wave_format_ex_raw['wBitsPerSample']) {
			$wave_format_ex['bits_per_sample'] = $wave_format_ex_raw['wBitsPerSample'];
		}

		return $wave_format_ex;
	}



	public static function RIFFwaveSNDMtagLookup($tagshortname) {
		static $lookup = array(
			'©kwd' => 'keywords',
			'©BPM' => 'bpm',
			'©trt' => 'tracktitle',
			'©des' => 'description',
			'©gen' => 'category',
			'©fin' => 'featuredinstrument',
			'©LID' => 'longid',
			'©bex' => 'bwdescription',
			'©pub' => 'publisher',
			'©cdt' => 'cdtitle',
			'©alb' => 'library',
			'©com' => 'composer',
		);
		return @$lookup[$tagshortname];
	}


	public static function RIFFwFormatTagLookup($w_format_tag) {

		static $lookup = array (
			0x0000 => 'Microsoft Unknown Wave Format',
			0x0001 => 'Pulse Code Modulation (PCM)',
			0x0002 => 'Microsoft ADPCM',
			0x0003 => 'IEEE Float',
			0x0004 => 'Compaq Computer VSELP',
			0x0005 => 'IBM CVSD',
			0x0006 => 'Microsoft A-Law',
			0x0007 => 'Microsoft mu-Law',
			0x0008 => 'Microsoft DTS',
			0x0010 => 'OKI ADPCM',
			0x0011 => 'Intel DVI/IMA ADPCM',
			0x0012 => 'Videologic MediaSpace ADPCM',
			0x0013 => 'Sierra Semiconductor ADPCM',
			0x0014 => 'Antex Electronics G.723 ADPCM',
			0x0015 => 'DSP Solutions DigiSTD',
			0x0016 => 'DSP Solutions DigiFIX',
			0x0017 => 'Dialogic OKI ADPCM',
			0x0018 => 'MediaVision ADPCM',
			0x0019 => 'Hewlett-Packard CU',
			0x0020 => 'Yamaha ADPCM',
			0x0021 => 'Speech Compression Sonarc',
			0x0022 => 'DSP Group TrueSpeech',
			0x0023 => 'Echo Speech EchoSC1',
			0x0024 => 'Audiofile AF36',
			0x0025 => 'Audio Processing Technology APTX',
			0x0026 => 'AudioFile AF10',
			0x0027 => 'Prosody 1612',
			0x0028 => 'LRC',
			0x0030 => 'Dolby AC2',
			0x0031 => 'Microsoft GSM 6.10',
			0x0032 => 'MSNAudio',
			0x0033 => 'Antex Electronics ADPCME',
			0x0034 => 'Control Resources VQLPC',
			0x0035 => 'DSP Solutions DigiREAL',
			0x0036 => 'DSP Solutions DigiADPCM',
			0x0037 => 'Control Resources CR10',
			0x0038 => 'Natural MicroSystems VBXADPCM',
			0x0039 => 'Crystal Semiconductor IMA ADPCM',
			0x003A => 'EchoSC3',
			0x003B => 'Rockwell ADPCM',
			0x003C => 'Rockwell Digit LK',
			0x003D => 'Xebec',
			0x0040 => 'Antex Electronics G.721 ADPCM',
			0x0041 => 'G.728 CELP',
			0x0042 => 'MSG723',
			0x0050 => 'MPEG Layer-2 or Layer-1',
			0x0052 => 'RT24',
			0x0053 => 'PAC',
			0x0055 => 'MPEG Layer-3',
			0x0059 => 'Lucent G.723',
			0x0060 => 'Cirrus',
			0x0061 => 'ESPCM',
			0x0062 => 'Voxware',
			0x0063 => 'Canopus Atrac',
			0x0064 => 'G.726 ADPCM',
			0x0065 => 'G.722 ADPCM',
			0x0066 => 'DSAT',
			0x0067 => 'DSAT Display',
			0x0069 => 'Voxware Byte Aligned',
			0x0070 => 'Voxware AC8',
			0x0071 => 'Voxware AC10',
			0x0072 => 'Voxware AC16',
			0x0073 => 'Voxware AC20',
			0x0074 => 'Voxware MetaVoice',
			0x0075 => 'Voxware MetaSound',
			0x0076 => 'Voxware RT29HW',
			0x0077 => 'Voxware VR12',
			0x0078 => 'Voxware VR18',
			0x0079 => 'Voxware TQ40',
			0x0080 => 'Softsound',
			0x0081 => 'Voxware TQ60',
			0x0082 => 'MSRT24',
			0x0083 => 'G.729A',
			0x0084 => 'MVI MV12',
			0x0085 => 'DF G.726',
			0x0086 => 'DF GSM610',
			0x0088 => 'ISIAudio',
			0x0089 => 'Onlive',
			0x0091 => 'SBC24',
			0x0092 => 'Dolby AC3 SPDIF',
			0x0093 => 'MediaSonic G.723',
			0x0094 => 'Aculab PLC    Prosody 8kbps',
			0x0097 => 'ZyXEL ADPCM',
			0x0098 => 'Philips LPCBB',
			0x0099 => 'Packed',
			0x00FF => 'AAC',
			0x0100 => 'Rhetorex ADPCM',
			0x0101 => 'IBM mu-law',
			0x0102 => 'IBM A-law',
			0x0103 => 'IBM AVC Adaptive Differential Pulse Code Modulation (ADPCM)',
			0x0111 => 'Vivo G.723',
			0x0112 => 'Vivo Siren',
			0x0123 => 'Digital G.723',
			0x0125 => 'Sanyo LD ADPCM',
			0x0130 => 'Sipro Lab Telecom ACELP NET',
			0x0131 => 'Sipro Lab Telecom ACELP 4800',
			0x0132 => 'Sipro Lab Telecom ACELP 8V3',
			0x0133 => 'Sipro Lab Telecom G.729',
			0x0134 => 'Sipro Lab Telecom G.729A',
			0x0135 => 'Sipro Lab Telecom Kelvin',
			0x0140 => 'Windows Media Video V8',
			0x0150 => 'Qualcomm PureVoice',
			0x0151 => 'Qualcomm HalfRate',
			0x0155 => 'Ring Zero Systems TUB GSM',
			0x0160 => 'Microsoft Audio 1',
			0x0161 => 'Windows Media Audio V7 / V8 / V9',
			0x0162 => 'Windows Media Audio Professional V9',
			0x0163 => 'Windows Media Audio Lossless V9',
			0x0200 => 'Creative Labs ADPCM',
			0x0202 => 'Creative Labs Fastspeech8',
			0x0203 => 'Creative Labs Fastspeech10',
			0x0210 => 'UHER Informatic GmbH ADPCM',
			0x0220 => 'Quarterdeck',
			0x0230 => 'I-link Worldwide VC',
			0x0240 => 'Aureal RAW Sport',
			0x0250 => 'Interactive Products HSX',
			0x0251 => 'Interactive Products RPELP',
			0x0260 => 'Consistent Software CS2',
			0x0270 => 'Sony SCX',
			0x0300 => 'Fujitsu FM Towns Snd',
			0x0400 => 'BTV Digital',
			0x0401 => 'Intel Music Coder',
			0x0450 => 'QDesign Music',
			0x0680 => 'VME VMPCM',
			0x0681 => 'AT&T Labs TPC',
			0x08AE => 'ClearJump LiteWave',
			0x1000 => 'Olivetti GSM',
			0x1001 => 'Olivetti ADPCM',
			0x1002 => 'Olivetti CELP',
			0x1003 => 'Olivetti SBC',
			0x1004 => 'Olivetti OPR',
			0x1100 => 'Lernout & Hauspie Codec (0x1100)',
			0x1101 => 'Lernout & Hauspie CELP Codec (0x1101)',
			0x1102 => 'Lernout & Hauspie SBC Codec (0x1102)',
			0x1103 => 'Lernout & Hauspie SBC Codec (0x1103)',
			0x1104 => 'Lernout & Hauspie SBC Codec (0x1104)',
			0x1400 => 'Norris',
			0x1401 => 'AT&T ISIAudio',
			0x1500 => 'Soundspace Music Compression',
			0x181C => 'VoxWare RT24 Speech',
			0x1FC4 => 'NCT Soft ALF2CD (www.nctsoft.com)',
			0x2000 => 'Dolby AC3',
			0x2001 => 'Dolby DTS',
			0x2002 => 'WAVE_FORMAT_14_4',
			0x2003 => 'WAVE_FORMAT_28_8',
			0x2004 => 'WAVE_FORMAT_COOK',
			0x2005 => 'WAVE_FORMAT_DNET',
			0x674F => 'Ogg Vorbis 1',
			0x6750 => 'Ogg Vorbis 2',
			0x6751 => 'Ogg Vorbis 3',
			0x676F => 'Ogg Vorbis 1+',
			0x6770 => 'Ogg Vorbis 2+',
			0x6771 => 'Ogg Vorbis 3+',
			0x7A21 => 'GSM-AMR (CBR, no SID)',
			0x7A22 => 'GSM-AMR (VBR, including SID)',
			0xFFFE => 'WAVE_FORMAT_EXTENSIBLE',
			0xFFFF => 'WAVE_FORMAT_DEVELOPMENT'
		);

		return @$lookup[$w_format_tag];
	}



	public static function RIFFfourccLookup($four_cc) {

		static $lookup = array (
			'swot' => 'http://developer.apple.com/qa/snd/snd07.html',
			'____' => 'No Codec (____)',
			'_BIT' => 'BI_BITFIELDS (Raw RGB)',
			'_JPG' => 'JPEG compressed',
			'_PNG' => 'PNG compressed W3C/ISO/IEC (RFC-2083)',
			'_RAW' => 'Full Frames (Uncompressed)',
			'_RGB' => 'Raw RGB Bitmap',
			'_RL4' => 'RLE 4bpp RGB',
			'_RL8' => 'RLE 8bpp RGB',
			'3IV1' => '3ivx MPEG-4 v1',
			'3IV2' => '3ivx MPEG-4 v2',
			'3IVX' => '3ivx MPEG-4',
			'AASC' => 'Autodesk Animator',
			'ABYR' => 'Kensington ?ABYR?',
			'AEMI' => 'Array Microsystems VideoONE MPEG1-I Capture',
			'AFLC' => 'Autodesk Animator FLC',
			'AFLI' => 'Autodesk Animator FLI',
			'AMPG' => 'Array Microsystems VideoONE MPEG',
			'ANIM' => 'Intel RDX (ANIM)',
			'AP41' => 'AngelPotion Definitive',
			'ASV1' => 'Asus Video v1',
			'ASV2' => 'Asus Video v2',
			'ASVX' => 'Asus Video 2.0 (audio)',
			'AUR2' => 'AuraVision Aura 2 Codec - YUV 4:2:2',
			'AURA' => 'AuraVision Aura 1 Codec - YUV 4:1:1',
			'AVDJ' => 'Independent JPEG Group\'s codec (AVDJ)',
			'AVRN' => 'Independent JPEG Group\'s codec (AVRN)',
			'AYUV' => '4:4:4 YUV (AYUV)',
			'AZPR' => 'Quicktime Apple Video (AZPR)',
			'BGR ' => 'Raw RGB32',
			'BLZ0' => 'FFmpeg MPEG-4',
			'BTVC' => 'Conexant Composite Video',
			'BINK' => 'RAD Game Tools Bink Video',
			'BT20' => 'Conexant Prosumer Video',
			'BTCV' => 'Conexant Composite Video Codec',
			'BW10' => 'Data Translation Broadway MPEG Capture',
			'CC12' => 'Intel YUV12',
			'CDVC' => 'Canopus DV',
			'CFCC' => 'Digital Processing Systems DPS Perception',
			'CGDI' => 'Microsoft Office 97 Camcorder Video',
			'CHAM' => 'Winnov Caviara Champagne',
			'CJPG' => 'Creative WebCam JPEG',
			'CLJR' => 'Cirrus Logic YUV 4:1:1',
			'CMYK' => 'Common Data Format in Printing (Colorgraph)',
			'CPLA' => 'Weitek 4:2:0 YUV Planar',
			'CRAM' => 'Microsoft Video 1 (CRAM)',
			'cvid' => 'Radius Cinepak',
			'CVID' => 'Radius Cinepak',
			'CWLT' => 'Microsoft Color WLT DIB',
			'CYUV' => 'Creative Labs YUV',
			'CYUY' => 'ATI YUV',
			'D261' => 'H.261',
			'D263' => 'H.263',
			'DIB ' => 'Device Independent Bitmap',
			'DIV1' => 'FFmpeg OpenDivX',
			'DIV2' => 'Microsoft MPEG-4 v1/v2',
			'DIV3' => 'DivX ;-) MPEG-4 v3.x Low-Motion',
			'DIV4' => 'DivX ;-) MPEG-4 v3.x Fast-Motion',
			'DIV5' => 'DivX MPEG-4 v5.x',
			'DIV6' => 'DivX ;-) (MS MPEG-4 v3.x)',
			'DIVX' => 'DivX MPEG-4 v4 (OpenDivX / Project Mayo)',
			'divx' => 'DivX MPEG-4',
			'DMB1' => 'Matrox Rainbow Runner hardware MJPEG',
			'DMB2' => 'Paradigm MJPEG',
			'DSVD' => '?DSVD?',
			'DUCK' => 'Duck TrueMotion 1.0',
			'DPS0' => 'DPS/Leitch Reality Motion JPEG',
			'DPSC' => 'DPS/Leitch PAR Motion JPEG',
			'DV25' => 'Matrox DVCPRO codec',
			'DV50' => 'Matrox DVCPRO50 codec',
			'DVC ' => 'IEC 61834 and SMPTE 314M (DVC/DV Video)',
			'DVCP' => 'IEC 61834 and SMPTE 314M (DVC/DV Video)',
			'DVHD' => 'IEC Standard DV 1125 lines @ 30fps / 1250 lines @ 25fps',
			'DVMA' => 'Darim Vision DVMPEG (dummy for MPEG compressor) (www.darvision.com)',
			'DVSL' => 'IEC Standard DV compressed in SD (SDL)',
			'DVAN' => '?DVAN?',
			'DVE2' => 'InSoft DVE-2 Videoconferencing',
			'dvsd' => 'IEC 61834 and SMPTE 314M DVC/DV Video',
			'DVSD' => 'IEC 61834 and SMPTE 314M DVC/DV Video',
			'DVX1' => 'Lucent DVX1000SP Video Decoder',
			'DVX2' => 'Lucent DVX2000S Video Decoder',
			'DVX3' => 'Lucent DVX3000S Video Decoder',
			'DX50' => 'DivX v5',
			'DXT1' => 'Microsoft DirectX Compressed Texture (DXT1)',
			'DXT2' => 'Microsoft DirectX Compressed Texture (DXT2)',
			'DXT3' => 'Microsoft DirectX Compressed Texture (DXT3)',
			'DXT4' => 'Microsoft DirectX Compressed Texture (DXT4)',
			'DXT5' => 'Microsoft DirectX Compressed Texture (DXT5)',
			'DXTC' => 'Microsoft DirectX Compressed Texture (DXTC)',
			'DXTn' => 'Microsoft DirectX Compressed Texture (DXTn)',
			'EM2V' => 'Etymonix MPEG-2 I-frame (www.etymonix.com)',
			'EKQ0' => 'Elsa ?EKQ0?',
			'ELK0' => 'Elsa ?ELK0?',
			'ESCP' => 'Eidos Escape',
			'ETV1' => 'eTreppid Video ETV1',
			'ETV2' => 'eTreppid Video ETV2',
			'ETVC' => 'eTreppid Video ETVC',
			'FLIC' => 'Autodesk FLI/FLC Animation',
			'FRWT' => 'Darim Vision Forward Motion JPEG (www.darvision.com)',
			'FRWU' => 'Darim Vision Forward Uncompressed (www.darvision.com)',
			'FLJP' => 'D-Vision Field Encoded Motion JPEG',
			'FRWA' => 'SoftLab-Nsk Forward Motion JPEG w/ alpha channel',
			'FRWD' => 'SoftLab-Nsk Forward Motion JPEG',
			'FVF1' => 'Iterated Systems Fractal Video Frame',
			'GLZW' => 'Motion LZW (gabest@freemail.hu)',
			'GPEG' => 'Motion JPEG (gabest@freemail.hu)',
			'GWLT' => 'Microsoft Greyscale WLT DIB',
			'H260' => 'Intel ITU H.260 Videoconferencing',
			'H261' => 'Intel ITU H.261 Videoconferencing',
			'H262' => 'Intel ITU H.262 Videoconferencing',
			'H263' => 'Intel ITU H.263 Videoconferencing',
			'H264' => 'Intel ITU H.264 Videoconferencing',
			'H265' => 'Intel ITU H.265 Videoconferencing',
			'H266' => 'Intel ITU H.266 Videoconferencing',
			'H267' => 'Intel ITU H.267 Videoconferencing',
			'H268' => 'Intel ITU H.268 Videoconferencing',
			'H269' => 'Intel ITU H.269 Videoconferencing',
			'HFYU' => 'Huffman Lossless Codec',
			'HMCR' => 'Rendition Motion Compensation Format (HMCR)',
			'HMRR' => 'Rendition Motion Compensation Format (HMRR)',
			'I263' => 'FFmpeg I263 decoder',
			'IF09' => 'Indeo YVU9 ("YVU9 with additional delta-frame info after the U plane")',
			'IUYV' => 'Interlaced version of UYVY (www.leadtools.com)',
			'IY41' => 'Interlaced version of Y41P (www.leadtools.com)',
			'IYU1' => '12 bit format used in mode 2 of the IEEE 1394 Digital Camera 1.04 spec    IEEE standard',
			'IYU2' => '24 bit format used in mode 2 of the IEEE 1394 Digital Camera 1.04 spec    IEEE standard',
			'IYUV' => 'Planar YUV format (8-bpp Y plane, followed by 8-bpp 2×2 U and V planes)',
			'i263' => 'Intel ITU H.263 Videoconferencing (i263)',
			'I420' => 'Intel Indeo 4',
			'IAN ' => 'Intel Indeo 4 (RDX)',
			'ICLB' => 'InSoft CellB Videoconferencing',
			'IGOR' => 'Power DVD',
			'IJPG' => 'Intergraph JPEG',
			'ILVC' => 'Intel Layered Video',
			'ILVR' => 'ITU-T H.263+',
			'IPDV' => 'I-O Data Device Giga AVI DV Codec',
			'IR21' => 'Intel Indeo 2.1',
			'IRAW' => 'Intel YUV Uncompressed',
			'IV30' => 'Intel Indeo 3.0',
			'IV31' => 'Intel Indeo 3.1',
			'IV32' => 'Ligos Indeo 3.2',
			'IV33' => 'Ligos Indeo 3.3',
			'IV34' => 'Ligos Indeo 3.4',
			'IV35' => 'Ligos Indeo 3.5',
			'IV36' => 'Ligos Indeo 3.6',
			'IV37' => 'Ligos Indeo 3.7',
			'IV38' => 'Ligos Indeo 3.8',
			'IV39' => 'Ligos Indeo 3.9',
			'IV40' => 'Ligos Indeo Interactive 4.0',
			'IV41' => 'Ligos Indeo Interactive 4.1',
			'IV42' => 'Ligos Indeo Interactive 4.2',
			'IV43' => 'Ligos Indeo Interactive 4.3',
			'IV44' => 'Ligos Indeo Interactive 4.4',
			'IV45' => 'Ligos Indeo Interactive 4.5',
			'IV46' => 'Ligos Indeo Interactive 4.6',
			'IV47' => 'Ligos Indeo Interactive 4.7',
			'IV48' => 'Ligos Indeo Interactive 4.8',
			'IV49' => 'Ligos Indeo Interactive 4.9',
			'IV50' => 'Ligos Indeo Interactive 5.0',
			'JBYR' => 'Kensington ?JBYR?',
			'JPEG' => 'Still Image JPEG DIB',
			'JPGL' => 'Pegasus Lossless Motion JPEG',
			'KMVC' => 'Team17 Software Karl Morton\'s Video Codec',
			'LSVM' => 'Vianet Lighting Strike Vmail (Streaming) (www.vianet.com)',
			'LEAD' => 'LEAD Video Codec',
			'Ljpg' => 'LEAD MJPEG Codec',
			'MDVD' => 'Alex MicroDVD Video (hacked MS MPEG-4) (www.tiasoft.de)',
			'MJPA' => 'Morgan Motion JPEG (MJPA) (www.morgan-multimedia.com)',
			'MJPB' => 'Morgan Motion JPEG (MJPB) (www.morgan-multimedia.com)',
			'MMES' => 'Matrox MPEG-2 I-frame',
			'MP2v' => 'Microsoft S-Mpeg 4 version 1 (MP2v)',
			'MP42' => 'Microsoft S-Mpeg 4 version 2 (MP42)',
			'MP43' => 'Microsoft S-Mpeg 4 version 3 (MP43)',
			'MP4S' => 'Microsoft S-Mpeg 4 version 3 (MP4S)',
			'MP4V' => 'FFmpeg MPEG-4',
			'MPG1' => 'FFmpeg MPEG 1/2',
			'MPG2' => 'FFmpeg MPEG 1/2',
			'MPG3' => 'FFmpeg DivX ;-) (MS MPEG-4 v3)',
			'MPG4' => 'Microsoft MPEG-4',
			'MPGI' => 'Sigma Designs MPEG',
			'MPNG' => 'PNG images decoder',
			'MSS1' => 'Microsoft Windows Screen Video',
			'MSZH' => 'LCL (Lossless Codec Library) (www.geocities.co.jp/Playtown-Denei/2837/LRC.htm)',
			'M261' => 'Microsoft H.261',
			'M263' => 'Microsoft H.263',
			'M4S2' => 'Microsoft Fully Compliant MPEG-4 v2 simple profile (M4S2)',
			'm4s2' => 'Microsoft Fully Compliant MPEG-4 v2 simple profile (m4s2)',
			'MC12' => 'ATI Motion Compensation Format (MC12)',
			'MCAM' => 'ATI Motion Compensation Format (MCAM)',
			'MJ2C' => 'Morgan Multimedia Motion JPEG2000',
			'mJPG' => 'IBM Motion JPEG w/ Huffman Tables',
			'MJPG' => 'Microsoft Motion JPEG DIB',
			'MP42' => 'Microsoft MPEG-4 (low-motion)',
			'MP43' => 'Microsoft MPEG-4 (fast-motion)',
			'MP4S' => 'Microsoft MPEG-4 (MP4S)',
			'mp4s' => 'Microsoft MPEG-4 (mp4s)',
			'MPEG' => 'Chromatic Research MPEG-1 Video I-Frame',
			'MPG4' => 'Microsoft MPEG-4 Video High Speed Compressor',
			'MPGI' => 'Sigma Designs MPEG',
			'MRCA' => 'FAST Multimedia Martin Regen Codec',
			'MRLE' => 'Microsoft Run Length Encoding',
			'MSVC' => 'Microsoft Video 1',
			'MTX1' => 'Matrox ?MTX1?',
			'MTX2' => 'Matrox ?MTX2?',
			'MTX3' => 'Matrox ?MTX3?',
			'MTX4' => 'Matrox ?MTX4?',
			'MTX5' => 'Matrox ?MTX5?',
			'MTX6' => 'Matrox ?MTX6?',
			'MTX7' => 'Matrox ?MTX7?',
			'MTX8' => 'Matrox ?MTX8?',
			'MTX9' => 'Matrox ?MTX9?',
			'MV12' => 'Motion Pixels Codec (old)',
			'MWV1' => 'Aware Motion Wavelets',
			'nAVI' => 'SMR Codec (hack of Microsoft MPEG-4) (IRC #shadowrealm)',
			'NT00' => 'NewTek LightWave HDTV YUV w/ Alpha (www.newtek.com)',
			'NUV1' => 'NuppelVideo',
			'NTN1' => 'Nogatech Video Compression 1',
			'NVS0' => 'nVidia GeForce Texture (NVS0)',
			'NVS1' => 'nVidia GeForce Texture (NVS1)',
			'NVS2' => 'nVidia GeForce Texture (NVS2)',
			'NVS3' => 'nVidia GeForce Texture (NVS3)',
			'NVS4' => 'nVidia GeForce Texture (NVS4)',
			'NVS5' => 'nVidia GeForce Texture (NVS5)',
			'NVT0' => 'nVidia GeForce Texture (NVT0)',
			'NVT1' => 'nVidia GeForce Texture (NVT1)',
			'NVT2' => 'nVidia GeForce Texture (NVT2)',
			'NVT3' => 'nVidia GeForce Texture (NVT3)',
			'NVT4' => 'nVidia GeForce Texture (NVT4)',
			'NVT5' => 'nVidia GeForce Texture (NVT5)',
			'PIXL' => 'MiroXL, Pinnacle PCTV',
			'PDVC' => 'I-O Data Device Digital Video Capture DV codec',
			'PGVV' => 'Radius Video Vision',
			'PHMO' => 'IBM Photomotion',
			'PIM1' => 'MPEG Realtime (Pinnacle Cards)',
			'PIM2' => 'Pegasus Imaging ?PIM2?',
			'PIMJ' => 'Pegasus Imaging Lossless JPEG',
			'PVEZ' => 'Horizons Technology PowerEZ',
			'PVMM' => 'PacketVideo Corporation MPEG-4',
			'PVW2' => 'Pegasus Imaging Wavelet Compression',
			'Q1.0' => 'Q-Team\'s QPEG 1.0 (www.q-team.de)',
			'Q1.1' => 'Q-Team\'s QPEG 1.1 (www.q-team.de)',
			'QPEG' => 'Q-Team QPEG 1.0',
			'qpeq' => 'Q-Team QPEG 1.1',
			'RGB ' => 'Raw BGR32',
			'RGBA' => 'Raw RGB w/ Alpha',
			'RMP4' => 'REALmagic MPEG-4 (unauthorized XVID copy) (www.sigmadesigns.com)',
			'ROQV' => 'Id RoQ File Video Decoder',
			'RPZA' => 'Quicktime Apple Video (RPZA)',
			'RUD0' => 'Rududu video codec (http://rududu.ifrance.com/rududu/)',
			'RV10' => 'RealVideo 1.0 (aka RealVideo 5.0)',
			'RV13' => 'RealVideo 1.0 (RV13)',
			'RV20' => 'RealVideo G2',
			'RV30' => 'RealVideo 8',
			'RV40' => 'RealVideo 9',
			'RGBT' => 'Raw RGB w/ Transparency',
			'RLE ' => 'Microsoft Run Length Encoder',
			'RLE4' => 'Run Length Encoded (4bpp, 16-color)',
			'RLE8' => 'Run Length Encoded (8bpp, 256-color)',
			'RT21' => 'Intel Indeo RealTime Video 2.1',
			'rv20' => 'RealVideo G2',
			'rv30' => 'RealVideo 8',
			'RVX ' => 'Intel RDX (RVX )',
			'SMC ' => 'Apple Graphics (SMC )',
			'SP54' => 'Logitech Sunplus Sp54 Codec for Mustek GSmart Mini 2',
			'SPIG' => 'Radius Spigot',
			'SVQ3' => 'Sorenson Video 3 (Apple Quicktime 5)',
			's422' => 'Tekram VideoCap C210 YUV 4:2:2',
			'SDCC' => 'Sun Communication Digital Camera Codec',
			'SFMC' => 'CrystalNet Surface Fitting Method',
			'SMSC' => 'Radius SMSC',
			'SMSD' => 'Radius SMSD',
			'smsv' => 'WorldConnect Wavelet Video',
			'SPIG' => 'Radius Spigot',
			'SPLC' => 'Splash Studios ACM Audio Codec (www.splashstudios.net)',
			'SQZ2' => 'Microsoft VXTreme Video Codec V2',
			'STVA' => 'ST Microelectronics CMOS Imager Data (Bayer)',
			'STVB' => 'ST Microelectronics CMOS Imager Data (Nudged Bayer)',
			'STVC' => 'ST Microelectronics CMOS Imager Data (Bunched)',
			'STVX' => 'ST Microelectronics CMOS Imager Data (Extended CODEC Data Format)',
			'STVY' => 'ST Microelectronics CMOS Imager Data (Extended CODEC Data Format with Correction Data)',
			'SV10' => 'Sorenson Video R1',
			'SVQ1' => 'Sorenson Video',
			'T420' => 'Toshiba YUV 4:2:0',
			'TM2A' => 'Duck TrueMotion Archiver 2.0 (www.duck.com)',
			'TVJP' => 'Pinnacle/Truevision Targa 2000 board (TVJP)',
			'TVMJ' => 'Pinnacle/Truevision Targa 2000 board (TVMJ)',
			'TY0N' => 'Tecomac Low-Bit Rate Codec (www.tecomac.com)',
			'TY2C' => 'Trident Decompression Driver',
			'TLMS' => 'TeraLogic Motion Intraframe Codec (TLMS)',
			'TLST' => 'TeraLogic Motion Intraframe Codec (TLST)',
			'TM20' => 'Duck TrueMotion 2.0',
			'TM2X' => 'Duck TrueMotion 2X',
			'TMIC' => 'TeraLogic Motion Intraframe Codec (TMIC)',
			'TMOT' => 'Horizons Technology TrueMotion S',
			'tmot' => 'Horizons TrueMotion Video Compression',
			'TR20' => 'Duck TrueMotion RealTime 2.0',
			'TSCC' => 'TechSmith Screen Capture Codec',
			'TV10' => 'Tecomac Low-Bit Rate Codec',
			'TY2N' => 'Trident ?TY2N?',
			'U263' => 'UB Video H.263/H.263+/H.263++ Decoder',
			'UMP4' => 'UB Video MPEG 4 (www.ubvideo.com)',
			'UYNV' => 'Nvidia UYVY packed 4:2:2',
			'UYVP' => 'Evans & Sutherland YCbCr 4:2:2 extended precision',
			'UCOD' => 'eMajix.com ClearVideo',
			'ULTI' => 'IBM Ultimotion',
			'UYVY' => 'UYVY packed 4:2:2',
			'V261' => 'Lucent VX2000S',
			'VIFP' => 'VFAPI Reader Codec (www.yks.ne.jp/~hori/)',
			'VIV1' => 'FFmpeg H263+ decoder',
			'VIV2' => 'Vivo H.263',
			'VQC2' => 'Vector-quantised codec 2 (research) http://eprints.ecs.soton.ac.uk/archive/00001310/01/VTC97-js.pdf)',
			'VTLP' => 'Alaris VideoGramPiX',
			'VYU9' => 'ATI YUV (VYU9)',
			'VYUY' => 'ATI YUV (VYUY)',
			'V261' => 'Lucent VX2000S',
			'V422' => 'Vitec Multimedia 24-bit YUV 4:2:2 Format',
			'V655' => 'Vitec Multimedia 16-bit YUV 4:2:2 Format',
			'VCR1' => 'ATI Video Codec 1',
			'VCR2' => 'ATI Video Codec 2',
			'VCR3' => 'ATI VCR 3.0',
			'VCR4' => 'ATI VCR 4.0',
			'VCR5' => 'ATI VCR 5.0',
			'VCR6' => 'ATI VCR 6.0',
			'VCR7' => 'ATI VCR 7.0',
			'VCR8' => 'ATI VCR 8.0',
			'VCR9' => 'ATI VCR 9.0',
			'VDCT' => 'Vitec Multimedia Video Maker Pro DIB',
			'VDOM' => 'VDOnet VDOWave',
			'VDOW' => 'VDOnet VDOLive (H.263)',
			'VDTZ' => 'Darim Vison VideoTizer YUV',
			'VGPX' => 'Alaris VideoGramPiX',
			'VIDS' => 'Vitec Multimedia YUV 4:2:2 CCIR 601 for V422',
			'VIVO' => 'Vivo H.263 v2.00',
			'vivo' => 'Vivo H.263',
			'VIXL' => 'Miro/Pinnacle Video XL',
			'VLV1' => 'VideoLogic/PURE Digital Videologic Capture',
			'VP30' => 'On2 VP3.0',
			'VP31' => 'On2 VP3.1',
			'VX1K' => 'Lucent VX1000S Video Codec',
			'VX2K' => 'Lucent VX2000S Video Codec',
			'VXSP' => 'Lucent VX1000SP Video Codec',
			'WBVC' => 'Winbond W9960',
			'WHAM' => 'Microsoft Video 1 (WHAM)',
			'WINX' => 'Winnov Software Compression',
			'WJPG' => 'AverMedia Winbond JPEG',
			'WMV1' => 'Windows Media Video V7',
			'WMV2' => 'Windows Media Video V8',
			'WMV3' => 'Windows Media Video V9',
			'WNV1' => 'Winnov Hardware Compression',
			'XYZP' => 'Extended PAL format XYZ palette (www.riff.org)',
			'x263' => 'Xirlink H.263',
			'XLV0' => 'NetXL Video Decoder',
			'XMPG' => 'Xing MPEG (I-Frame only)',
			'XVID' => 'XviD MPEG-4 (www.xvid.org)',
			'XXAN' => '?XXAN?',
			'YU92' => 'Intel YUV (YU92)',
			'YUNV' => 'Nvidia Uncompressed YUV 4:2:2',
			'YUVP' => 'Extended PAL format YUV palette (www.riff.org)',
			'Y211' => 'YUV 2:1:1 Packed',
			'Y411' => 'YUV 4:1:1 Packed',
			'Y41B' => 'Weitek YUV 4:1:1 Planar',
			'Y41P' => 'Brooktree PC1 YUV 4:1:1 Packed',
			'Y41T' => 'Brooktree PC1 YUV 4:1:1 with transparency',
			'Y42B' => 'Weitek YUV 4:2:2 Planar',
			'Y42T' => 'Brooktree UYUV 4:2:2 with transparency',
			'Y422' => 'ADS Technologies Copy of UYVY used in Pyro WebCam firewire camera',
			'Y800' => 'Simple, single Y plane for monochrome images',
			'Y8  ' => 'Grayscale video',
			'YC12' => 'Intel YUV 12 codec',
			'YUV8' => 'Winnov Caviar YUV8',
			'YUV9' => 'Intel YUV9',
			'YUY2' => 'Uncompressed YUV 4:2:2',
			'YUYV' => 'Canopus YUV',
			'YV12' => 'YVU12 Planar',
			'YVU9' => 'Intel YVU9 Planar (8-bpp Y plane, followed by 8-bpp 4x4 U and V planes)',
			'YVYU' => 'YVYU 4:2:2 Packed',
			'ZLIB' => 'Lossless Codec Library zlib compression (www.geocities.co.jp/Playtown-Denei/2837/LRC.htm)',
			'ZPEG' => 'Metheus Video Zipper'
		);

		return @$lookup[$four_cc];
	}



	public static function RIFFcommentsParse(&$riff_info_aray, &$comments_target_array) {

		static $lookup = array(
			'IARL' => 'archivallocation',
			'IART' => 'artist',
			'ICDS' => 'costumedesigner',
			'ICMS' => 'commissionedby',
			'ICMT' => 'comment',
			'ICNT' => 'country',
			'ICOP' => 'copyright',
			'ICRD' => 'creationdate',
			'IDIM' => 'dimensions',
			'IDIT' => 'digitizationdate',
			'IDPI' => 'resolution',
			'IDST' => 'distributor',
			'IEDT' => 'editor',
			'IENG' => 'engineers',
			'IFRM' => 'accountofparts',
			'IGNR' => 'genre',
			'IKEY' => 'keywords',
			'ILGT' => 'lightness',
			'ILNG' => 'language',
			'IMED' => 'orignalmedium',
			'IMUS' => 'composer',
			'INAM' => 'title',
			'IPDS' => 'productiondesigner',
			'IPLT' => 'palette',
			'IPRD' => 'product',
			'IPRO' => 'producer',
			'IPRT' => 'part',
			'IRTD' => 'rating',
			'ISBJ' => 'subject',
			'ISFT' => 'software',
			'ISGN' => 'secondarygenre',
			'ISHP' => 'sharpness',
			'ISRC' => 'sourcesupplier',
			'ISRF' => 'digitizationsource',
			'ISTD' => 'productionstudio',
			'ISTR' => 'starring',
			'ITCH' => 'encoded_by',
			'IWEB' => 'url',
			'IWRI' => 'writer'
		);

		foreach ($lookup as $key => $value) {
			if (isset($riff_info_aray[$key])) {
				foreach ($riff_info_aray[$key] as $comment_id => $comment_data) {
					if (trim($comment_data['data']) != '') {
						@$comments_target_array[$value][] = trim($comment_data['data']);
					}
				}
			}
		}
		return true;
	}



	public static function array_merge_noclobber($array1, $array2) {
		if (!is_array($array1) || !is_array($array2)) {
			return false;
		}
		$new_array = $array1;
		foreach ($array2 as $key => $val) {
			if (is_array($val) && isset($new_array[$key]) && is_array($new_array[$key])) {
				$new_array[$key] = getid3_riff::array_merge_noclobber($new_array[$key], $val);
			} elseif (!isset($new_array[$key])) {
				$new_array[$key] = $val;
			}
		}
		return $new_array;
	}



	public static function DateMac2Unix($mac_date) {

		// Macintosh timestamp: seconds since 00:00h January 1, 1904
		// UNIX timestamp:      seconds since 00:00h January 1, 1970
		return (int)($mac_date - 2082844800);
	}



	public static function FixedPoint16_16($raw_data) {

		return getid3_lib::BigEndian2Int(substr($raw_data, 0, 2)) + (float)(getid3_lib::BigEndian2Int(substr($raw_data, 2, 2)) / 65536);  // pow(2, 16) = 65536
	}



   	function BigEndian2Float($byte_word) {

		// ANSI/IEEE Standard 754-1985, Standard for Binary Floating Point Arithmetic
		// http://www.psc.edu/general/software/packages/ieee/ieee.html
		// http://www.scri.fsu.edu/~jac/MAD3401/Backgrnd/ieee.html

		$bit_word = getid3_lib::BigEndian2Bin($byte_word);
		$sign_bit = $bit_word{0};

		switch (strlen($byte_word) * 8) {
			case 32:
				$exponent_bits = 8;
				$fraction_bits = 23;
				break;

			case 64:
				$exponent_bits = 11;
				$fraction_bits = 52;
				break;

			case 80:
				// 80-bit Apple SANE format
				// http://www.mactech.com/articles/mactech/Vol.06/06.01/SANENormalized/
				$exponent_string = substr($bit_word, 1, 15);
				$is_normalized   = intval($bit_word{16});
				$fraction_string = substr($bit_word, 17, 63);
				$exponent = pow(2, bindec($exponent_string) - 16383);
				$fraction = $is_normalized + bindec($fraction_string) / bindec('1'.str_repeat('0', strlen($fraction_string)));
				$float_value = $exponent * $fraction;
				if ($sign_bit == '1') {
					$float_value *= -1;
				}
				return $float_value;
				break;

			default:
				return false;
				break;
		}
		$exponent_string = substr($bit_word, 1, $exponent_bits);
		$fraction_string = substr($bit_word, $exponent_bits + 1, $fraction_bits);
		$exponent = bindec($exponent_string);
		$fraction = bindec($fraction_string);

		if (($exponent == (pow(2, $exponent_bits) - 1)) && ($fraction != 0)) {
			// Not a Number
			$float_value = false;
		} elseif (($exponent == (pow(2, $exponent_bits) - 1)) && ($fraction == 0)) {
			if ($sign_bit == '1') {
				$float_value = '-infinity';
			} else {
				$float_value = '+infinity';
			}
		} elseif (($exponent == 0) && ($fraction == 0)) {
			if ($sign_bit == '1') {
				$float_value = -0;
			} else {
				$float_value = 0;
			}
			$float_value = ($sign_bit ? 0 : -0);
		} elseif (($exponent == 0) && ($fraction != 0)) {
			// These are 'unnormalized' values
			$float_value = pow(2, (-1 * (pow(2, $exponent_bits - 1) - 2))) * bindec($fraction_string) / bindec('1'.str_repeat('0', strlen($fraction_string)));
			if ($sign_bit == '1') {
				$float_value *= -1;
			}
		} elseif ($exponent != 0) {
			$float_value = pow(2, ($exponent - (pow(2, $exponent_bits - 1) - 1))) * (1 + bindec($fraction_string) / bindec('1'.str_repeat('0', strlen($fraction_string))));
			if ($sign_bit == '1') {
				$float_value *= -1;
			}
		}
		return (float) $float_value;
	}
}



?>