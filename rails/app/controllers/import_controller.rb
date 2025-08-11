class ImportService
  require 'digest'

  COUNTRY_NAMES = {
    'GER' => 'Germany',
    'FRA' => 'France',
    'GBR' => 'Great Britain',
    'USA' => 'United States',
    'NED' => 'Netherlands',
    'BEL' => 'Belgium',
    'SUI' => 'Switzerland',
    'SWE' => 'Sweden',
    'ITA' => 'Italy',
    'ESP' => 'Spain',
    'AUT' => 'Austria',
    'IRL' => 'Ireland',
    'CAN' => 'Canada',
    'AUS' => 'Australia',
    'NZL' => 'New Zealand',
    'JPN' => 'Japan',
    'BRA' => 'Brazil',
    'ARG' => 'Argentina',
    'CHI' => 'Chile',
    'MEX' => 'Mexico',
    'NOR' => 'Norway',
    'DEN' => 'Denmark',
    'FIN' => 'Finland',
    'POL' => 'Poland',
    'CZE' => 'Czech Republic',
    'HUN' => 'Hungary',
    'POR' => 'Portugal',
    'RUS' => 'Russia',
    'UKR' => 'Ukraine',
    'RSA' => 'South Africa',
    'UAE' => 'United Arab Emirates',
    'KSA' => 'Saudi Arabia',
    'QAT' => 'Qatar',
    'HKG' => 'Hong Kong',
    'SGP' => 'Singapore',
    'IND' => 'India',
    'COL' => 'Colombia',
    'VEN' => 'Venezuela',
    'URY' => 'Uruguay',
    'ECU' => 'Ecuador',
    'ISR' => 'Israel',
    'TUR' => 'Turkey',
    'GRE' => 'Greece',
    'EGY' => 'Egypt',
    'MAR' => 'Morocco',
    'KOR' => 'South Korea',
    'TPE' => 'Chinese Taipei',
    'LUX' => 'Luxembourg',
    'EST' => 'Estonia',
    'LAT' => 'Latvia',
    'LTU' => 'Lithuania',
    'SVK' => 'Slovakia',
    'SLO' => 'Slovenia',
    'CRO' => 'Croatia',
    'BUL' => 'Bulgaria',
    'ROU' => 'Romania'
  }.freeze

  def initialize(api_key:, meeting_url:)
    @api_key = api_key
    @meeting_url = meeting_url
    @hippodata_service = HippodataService.new
  end

  def import_classes(show_id, selections)
    results = {
      classes: [],
      startlists: [],
      results: []
    }

    # Fetch event data
    hippodata_data = @hippodata_service.fetch_event(show_id)
    hippodata_classes = {}

    hippodata_data.dig('CLASSES', 'CLASS')&.each do |cls|
      hippodata_classes[cls['ID']] = cls
    end

    # Process selections
    classes_to_import = []
    startlists_to_process = []
    results_to_process = []
    counter = 1
    ord_counter = 0 # Counter starting at 0

    selections.each do |selection|
      class_id = selection['class_id']
      class_data = hippodata_classes[class_id]
      next unless class_data

      if selection['import_class']
        name = class_data['NAME'].presence || class_data['SPONSOR']

        # Extract time from DATETIME
        klock = ''
        if class_data['DATETIME'].present?
          # Format: "2025-02-12 09:00:00"
          date_time = DateTime.parse(class_data['DATETIME'])
          klock = date_time.strftime('%H:%M') # Format HH:MM
        end

        class_to_import = {
          'foreign_id' => class_data['ID'].to_s,
          'clabb' => "HD-#{counter}",
          'klass' => name,
          'oeverskr1' => name,
          'datum' => class_data['DATE'],
          'klock' => klock, # Time in HH:MM format
          'ord' => ord_counter, # Counter starting at 0
          'tavlingspl' => class_data['CATEGORY'] || '',
          'z' => 'H',
          'x' => 'I',
          'alias' => true,
          'premie_curr' => class_data.dig('PRIZE', 'CURRENCY') || 'EUR',
          'prsum1' => class_data.dig('PRIZE', 'MONEY') || 0
        }

        class_to_import['fei_article'] = selection['fei_article'] if selection['fei_article'].present?
        class_to_import['team_class'] = true if selection['team_class']

        classes_to_import << class_to_import
        counter += 1
        ord_counter += 1 # Increment ord counter

        results[:classes] << {
          'foreign_id' => class_data['ID'],
          'class_id' => class_data['NR'] || class_data['ID'],
          'name' => name,
          'status' => 'pending'
        }
      end

      if selection['import_startlist']
        startlists_to_process << {
          'foreign_id' => class_data['ID'],
          'class_id' => class_data['NR'] || class_data['ID'],
          'name' => class_data['NAME'].presence || class_data['SPONSOR'],
          'is_team' => selection['team_class']
        }
      end

      if selection['import_results']
        results_to_process << {
          'foreign_id' => class_data['ID'],
          'class_id' => class_data['NR'] || class_data['ID'],
          'name' => class_data['NAME'].presence || class_data['SPONSOR']
        }
      end
    end

    # Import classes if needed
    if classes_to_import.any?
      batch_data = {
        'competitions' => {
          'unique_by' => 'foreign_id',
          'skip_user_changed' => true,
          'records' => classes_to_import
        }
      }

      transaction_uuid = SecureRandom.uuid

      response = HTTParty.post(
        "#{@meeting_url}/batch",
        body: batch_data.to_json,
        headers: {
          'X-Api-Key' => @api_key,
          'X-Transaction-Uuid' => transaction_uuid,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json'
        }
      )

      # Update status
      results[:classes].each do |cls|
        cls['status'] = response.code.in?([200, 201]) ? 'success' : 'failed'
      end
    end

    {
      success: true,
      results: results,
      event_id: hippodata_data.dig('EVENT', 'ID') || show_id,
      startlists_to_process: startlists_to_process,
      results_to_process: results_to_process
    }
  end

  def import_startlists(event_id, competitions)
    # Get existing data from Equipe
    existing_people = fetch_existing_people
    existing_horses = fetch_existing_horses
    existing_clubs = fetch_existing_clubs

    all_batch_data = []
    processed_competitions = []

    competitions.each do |comp|
      class_id = comp['class_id']
      competition_foreign_id = comp['foreign_id']
      is_team_competition = comp['is_team'] || false

      begin
        startlist_data = @hippodata_service.fetch_startlist(event_id, class_id)

        competitors = startlist_data.dig('CLASS', 'COMPETITORS', 'COMPETITOR')
        next unless competitors

        # Ensure it's an array
        competitors = [competitors] if competitors.is_a?(Hash) && competitors['RIDER']

        new_people = []
        new_horses = []
        new_clubs = []
        new_teams = []
        starts = []

        # Process teams if team competition
        competitors_by_nation = {}
        teams_by_nation = {}
        team_counter = 1

        if is_team_competition
          # Group competitors by nation
          competitors.each do |competitor|
            rider = competitor['RIDER'] || {}
            nation = rider['NATION'] || ''
            club = rider['CLUB'] || ''

            if nation.present?
              competitors_by_nation[nation] ||= {
                'competitors' => [],
                'club_name' => club.presence || nation
              }
              competitors_by_nation[nation]['competitors'] << competitor
            end
          end

          # Create teams for nations with 3+ riders
          competitors_by_nation.each do |nation, data|
            if data['competitors'].size >= 3
              club_foreign_id = "club_#{nation}"
              team_foreign_id = "team_#{competition_foreign_id}_#{nation}"

              # Determine club name
              club_name = COUNTRY_NAMES[nation] || data['club_name']
              club_name = "#{club_name} Team" unless club_name.include?('Team')

              # Add club if not exists
              unless existing_clubs[club_foreign_id] || new_clubs.any? { |c| c['foreign_id'] == club_foreign_id }
                new_clubs << {
                  'foreign_id' => club_foreign_id,
                  'name' => club_name,
                  'logo_id' => nation,
                  'logo_group' => 'flags48'
                }
              end

              # Create team
              teams_by_nation[nation] = {
                'foreign_id' => team_foreign_id,
                'st' => team_counter,
                'ord' => team_counter,
                'lagnr' => team_counter,
                'lagledare' => '',
                'club' => { 'foreign_id' => club_foreign_id }
              }

              new_teams << teams_by_nation[nation]
              team_counter += 1
            end
          end
        end

        # Process each competitor
        competitors.each_with_index do |competitor, competitor_index|
          rider = competitor['RIDER'] || {}
          horse = competitor['HORSE'] || {}
          nation = rider['NATION'] || ''

          # Handle riders with or without FEI ID
          rider_fei_id = rider['RFEI_ID']
          rider_name = rider['RNAME'] || ''

          # If no FEI ID, create a temporary ID based on name and event
          if rider_fei_id.blank? && rider_name.present?
            # Create unique ID based on rider name and event ID
            rider_fei_id = "TEMP_R_#{event_id}_#{Digest::MD5.hexdigest(rider_name)}"

            Rails.logger.debug "Created temporary rider ID: #{rider_fei_id} for #{rider_name}" if Rails.configuration.debug_mode
          end

          # Check and prepare rider data
          if rider_fei_id && !existing_people[rider_fei_id]
            name_parts = rider_name.split(',')
            last_name = name_parts[0]&.strip || ''
            first_name = name_parts[1]&.strip || ''

            new_person = {
              'foreign_id' => rider_fei_id,
              'first_name' => first_name,
              'last_name' => last_name,
              'country' => nation.presence || 'XXX' # Default country code if not specified
            }

            # Add FEI ID only if it's real (not temporary)
            unless rider_fei_id.start_with?('TEMP_')
              new_person['fei_id'] = rider_fei_id
            end

            new_people << new_person
            existing_people[rider_fei_id] = true
          end

          # Handle horses with or without FEI ID
          horse_fei_id = horse['HFEI_ID']
          horse_name = horse['HNAME'] || ''
          horse_number = horse['HNR'] || ''

          # If no FEI ID, create a temporary ID
          if horse_fei_id.blank? && horse_name.present?
            # Create unique ID based on horse name, number and event ID
            horse_fei_id = "TEMP_H_#{event_id}_#{Digest::MD5.hexdigest("#{horse_name}_#{horse_number}")}"

            Rails.logger.debug "Created temporary horse ID: #{horse_fei_id} for #{horse_name}" if Rails.configuration.debug_mode
          end

          # Check and prepare horse data
          if horse_fei_id && !existing_horses[horse_fei_id]
            horse_info = horse['HORSEINFO'] || {}

            # Handle horse gender
            gender = (horse_info['GENDER'] || '').downcase
            sex_map = {
              'm' => 'val',
              'g' => 'val',
              'f' => 'sto',
              'mare' => 'sto',
              'stallion' => 'hin',
              'gelding' => 'val'
            }
            sex = sex_map[gender] || 'val'

            # Handle birth year
            born_year = horse_info['BORNYEAR'] || ''
            # If year is 2025 and age is 0, it's probably an error
            if born_year == 2025 && (horse_info['AGE'] || 0) == 0
              # Calculate birth year based on age if available
              if horse_info['AGE'] && horse_info['AGE'] > 0
                born_year = Date.current.year - horse_info['AGE']
              else
                born_year = '' # Leave empty if we can't determine
              end
            end

            new_horse = {
              'foreign_id' => horse_fei_id,
              'num' => horse_number,
              'name' => horse_name,
              'sex' => sex,
              'born_year' => born_year.to_s,
              'owner' => horse_info['OWNER'] || '',
              'category' => 'H'
            }

            # Add FEI ID only if it's real (not temporary)
            unless horse_fei_id.start_with?('TEMP_')
              new_horse['fei_id'] = horse_fei_id
            end

            # Add genealogical info if available
            new_horse['father'] = horse_info['FATHER'] if horse_info['FATHER'].present?
            new_horse['mother_father'] = horse_info['MOTHERFATHER'] if horse_info['MOTHERFATHER'].present?
            new_horse['breed'] = horse_info['BREED'] if horse_info['BREED'].present?
            new_horse['color'] = horse_info['COLOR'] if horse_info['COLOR'].present?

            new_horses << new_horse
            existing_horses[horse_fei_id] = true
          end

          # Prepare start entry
          if rider_fei_id && horse_fei_id
            sort_order = competitor.dig('SORTROUND', 'ROUND1') || competitor['SORTORDER'] || (competitor_index + 1)

            # For national competitions, handle club differently
            club_info = rider['CLUB'] || ''

            if is_team_competition && nation.present? && teams_by_nation[nation]
              # Start entry for team competition (only if team exists)
              starts << {
                'foreign_id' => "#{rider_fei_id}_#{horse_fei_id}_#{competition_foreign_id}",
                'st' => sort_order.to_s,
                'ord' => sort_order.to_i,
                'category' => 'H',
                'section' => 'A',
                'rider' => { 'foreign_id' => rider_fei_id },
                'horse' => { 'foreign_id' => horse_fei_id },
                'team' => { 'foreign_id' => teams_by_nation[nation]['foreign_id'] },
                'club' => { 'foreign_id' => "club_#{nation}" }
              }
            else
              # Normal start entry (individual or national)
              start_entry = {
                'foreign_id' => "#{rider_fei_id}_#{horse_fei_id}_#{competition_foreign_id}",
                'st' => sort_order.to_s,
                'ord' => sort_order.to_i,
                'rider' => { 'foreign_id' => rider_fei_id },
                'horse' => { 'foreign_id' => horse_fei_id }
              }

              # For national competitions, add club as text if available
              if club_info.present? && nation.blank?
                start_entry['club_text'] = club_info
              end

              starts << start_entry
            end
          else
            Rails.logger.debug "Skipping competitor - missing rider or horse ID" if Rails.configuration.debug_mode
            Rails.logger.debug "Rider: #{rider.to_json}" if Rails.configuration.debug_mode
            Rails.logger.debug "Horse: #{horse.to_json}" if Rails.configuration.debug_mode
          end
        end

        # Prepare batch data
        batch_data = {}

        batch_data['people'] = { 'unique_by' => 'foreign_id', 'records' => new_people } if new_people.any?
        batch_data['horses'] = { 'unique_by' => 'foreign_id', 'records' => new_horses } if new_horses.any?

        if is_team_competition
          batch_data['clubs'] = { 'unique_by' => 'foreign_id', 'records' => new_clubs } if new_clubs.any?

          if new_teams.any?
            batch_data['teams'] = {
              'unique_by' => 'foreign_id',
              'where' => { 'competition' => { 'foreign_id' => competition_foreign_id } },
              'records' => new_teams
            }
          end
        end

        if starts.any?
          batch_data['starts'] = {
            'unique_by' => 'foreign_id',
            'where' => { 'competition' => { 'foreign_id' => competition_foreign_id } },
            'abort_if_any' => { 'rid' => true },
            'replace' => true,
            'records' => starts
          }
        end

        if batch_data.any?
          all_batch_data << {
            'competition' => comp['name'],
            'competition_foreign_id' => competition_foreign_id,
            'is_team' => is_team_competition,
            'data' => batch_data,
            'details' => {
              'people' => new_people,
              'horses' => new_horses,
              'starts' => starts,
              'teams' => new_teams
            }
          }
        end

        processed_competitions << {
          'name' => comp['name'],
          'foreign_id' => competition_foreign_id,
          'people_count' => new_people.size,
          'horses_count' => new_horses.size,
          'starts_count' => starts.size,
          'teams_count' => new_teams.size,
          'is_team' => is_team_competition,
          'people' => new_people.map { |p| "#{p['first_name']} #{p['last_name']} (#{p['country']})" },
          'horses' => new_horses.map { |h| "#{h['name']} - #{h['fei_id']}" },
          'teams' => new_teams.map do |t|
            nation = t['club']['foreign_id'].sub('club_', '')
            team_name = COUNTRY_NAMES[nation] || nation
            "Team #{t['lagnr']} - #{team_name}"
          end
        }

      rescue => e
        processed_competitions << {
          'name' => comp['name'],
          'foreign_id' => competition_foreign_id,
          'people_count' => 0,
          'horses_count' => 0,
          'starts_count' => 0,
          'teams_count' => 0,
          'is_team' => is_team_competition,
          'error' => e.message
        }
      end
    end

    {
      success: true,
      message: 'Startlists ready for import',
      processedCompetitions: processed_competitions,
      batchData: all_batch_data
    }
  end

  def import_results(event_id, competitions)
    all_batch_data = []
    processed_competitions = []

    competitions.each do |comp|
      class_id = comp['class_id']
      competition_foreign_id = comp['foreign_id']
      is_team_competition = comp['is_team'] || false

      begin
        results_data = @hippodata_service.fetch_results(event_id, class_id)

        competitors = results_data.dig('CLASS', 'COMPETITORS', 'COMPETITOR')
        next unless competitors

        # Ensure it's an array
        competitors = [competitors] if competitors.is_a?(Hash) && competitors['RIDER']

        # Prepare competition update data
        competition_update = {}
        competition_update['grundt'] = results_data.dig('CLASS', 'TIME1_ALLOWED').to_i if results_data.dig('CLASS', 'TIME1_ALLOWED')
        competition_update['omh1t'] = results_data.dig('CLASS', 'TIME2_ALLOWED').to_i if results_data.dig('CLASS', 'TIME2_ALLOWED')
        competition_update['omh2t'] = results_data.dig('CLASS', 'TIME3_ALLOWED').to_i if results_data.dig('CLASS', 'TIME3_ALLOWED')
        competition_update['omg3t'] = results_data.dig('CLASS', 'TIME4_ALLOWED').to_i if results_data.dig('CLASS', 'TIME4_ALLOWED')
        competition_update['omg4t'] = results_data.dig('CLASS', 'TIME5_ALLOWED').to_i if results_data.dig('CLASS', 'TIME5_ALLOWED')
        competition_update['team'] = true if is_team_competition

        results = []

        # Find eliminated rank
        eliminated_rank = nil
        competitors.each do |comp|
          comp_result_total = comp.dig('RESULTTOTAL', 0) || {}
          if comp_result_total['STATUS'] && comp_result_total['STATUS'] != 1
            comp_status_text = (comp_result_total['TEXT'] || '').downcase
            if comp_status_text.in?(['eliminated', 'retired']) && comp_result_total['RANK']
              eliminated_rank = comp_result_total['RANK'].to_i
              break
            end
          end
        end

        # For team competitions, analyze results by nation to determine excluded riders
        riders_by_nation = {}
        riders_to_skip = {}

        if is_team_competition
          Rails.logger.debug "=== TEAM COMPETITION ANALYSIS FOR #{comp['name']} ===" if Rails.configuration.debug_mode
          Rails.logger.debug "Competition Foreign ID: #{competition_foreign_id}" if Rails.configuration.debug_mode
          Rails.logger.debug "Total competitors: #{competitors.size}" if Rails.configuration.debug_mode

          # First, group riders by nation and analyze their rounds
          idx = 0
          competitors.each do |competitor|
            rider = competitor['RIDER'] || {}
            nation = rider['NATION'] || ''
            if Rails.configuration.debug_mode && idx < 5 # Log first 5 for debug
              Rails.logger.debug "Competitor #{idx}:"
              Rails.logger.debug "  - RIDER data: #{rider.to_json}"
              Rails.logger.debug "  - Nation found: '#{nation}'"
            end
            idx += 1

            if nation.present?
              # Handle temporary IDs for national riders
              rider_fei_id = rider['RFEI_ID']
              horse_fei_id = competitor.dig('HORSE', 'HFEI_ID')

              # If no FEI ID, create the same temporary IDs as in import_startlists
              if rider_fei_id.blank? && rider['RNAME'].present?
                rider_fei_id = "TEMP_R_#{event_id}_#{Digest::MD5.hexdigest(rider['RNAME'])}"
              end
              if horse_fei_id.blank? && competitor.dig('HORSE', 'HNAME').present?
                horse_number = competitor.dig('HORSE', 'HNR') || ''
                horse_name = competitor.dig('HORSE', 'HNAME')
                horse_fei_id = "TEMP_H_#{event_id}_#{Digest::MD5.hexdigest("#{horse_name}_#{horse_number}")}"
              end

              result_details = competitor['RESULT'] || []

              # Check which rounds this rider completed
              has_round2 = false
              has_round3 = false
              result_details.each do |round_result|
                round_num = round_result['ROUND'] || 0
                has_round2 = true if round_num == 2
                has_round3 = true if round_num == 3
              end

              riders_by_nation[nation] ||= []

              riders_by_nation[nation] << {
                'rider_fei_id' => rider_fei_id,
                'horse_fei_id' => horse_fei_id,
                'has_round2' => has_round2,
                'has_round3' => has_round3,
                'foreign_id' => "#{rider_fei_id}_#{horse_fei_id}_#{competition_foreign_id}"
              }
            end
          end

          # For each nation, determine who should have skip_rounds
          riders_by_nation.each do |nation, riders|
            if Rails.configuration.debug_mode
              Rails.logger.debug "Nation '#{nation}' has #{riders.size} riders:"
              riders.each do |r|
                Rails.logger.debug "  - Rider #{r['rider_fei_id']} - Round2: #{r['has_round2'] ? 'YES' : 'NO'} - Round3: #{r['has_round3'] ? 'YES' : 'NO'}"
              end
            end

            if riders.size >= 4
              # Count how many have round 2
              riders_with_round2 = riders.select { |r| r['has_round2'] }

              # If exactly 3 have round 2, the 4th should have skip_rounds[2]
              if riders_with_round2.size == 3
                riders.each do |rider|
                  if !rider['has_round2'] && rider['foreign_id']
                    riders_to_skip[rider['foreign_id']] ||= []
                    riders_to_skip[rider['foreign_id']] << 2
                  end
                end
              end

              # Check if there's a round 3 (jump-off)
              riders_with_round3 = riders.select { |r| r['has_round3'] }
              # If at least one rider did round 3 AND there are multiple riders in the team
              if riders_with_round3.size > 0 && riders_with_round3.size < riders.size
                Rails.logger.debug "Round 3 detected: #{riders_with_round3.size} riders participated out of #{riders.size}" if Rails.configuration.debug_mode

                # If only 1 rider did round 3, others should have skip_rounds[3]
                if riders_with_round3.size == 1
                  riders.each do |rider|
                    if !rider['has_round3'] && rider['foreign_id']
                      riders_to_skip[rider['foreign_id']] ||= []
                      # Add 3 only if not already present
                      riders_to_skip[rider['foreign_id']] << 3 unless riders_to_skip[rider['foreign_id']].include?(3)
                    end
                  end
                end
              elsif riders_with_round3.size == 0 && Rails.configuration.debug_mode
                Rails.logger.debug "No round 3 detected for team '#{nation}' - no skip_rounds[3] needed"
              end
            end
          end

          Rails.logger.debug "Riders with skip rounds: #{riders_to_skip.to_json}" if Rails.configuration.debug_mode && riders_to_skip.any?
        end

        competitors.each do |competitor|
          rider = competitor['RIDER'] || {}
          horse = competitor['HORSE'] || {}

          # Handle IDs for national riders
          rider_fei_id = rider['RFEI_ID']
          horse_fei_id = horse['HFEI_ID']
          rider_name = rider['RNAME'] || ''
          horse_name = horse['HNAME'] || ''
          horse_number = horse['HNR'] || ''

          # Create temporary IDs if necessary (same logic as import_startlists)
          if rider_fei_id.blank? && rider_name.present?
            rider_fei_id = "TEMP_R_#{event_id}_#{Digest::MD5.hexdigest(rider_name)}"
            Rails.logger.debug "Using temporary rider ID for results: #{rider_fei_id} for #{rider_name}" if Rails.configuration.debug_mode
          end

          if horse_fei_id.blank? && horse_name.present?
            horse_fei_id = "TEMP_H_#{event_id}_#{Digest::MD5.hexdigest("#{horse_name}_#{horse_number}")}"
            Rails.logger.debug "Using temporary horse ID for results: #{horse_fei_id} for #{horse_name}" if Rails.configuration.debug_mode
          end

          if rider_fei_id.blank? || horse_fei_id.blank?
            if Rails.configuration.debug_mode
              Rails.logger.debug "Skipping result - missing rider or horse ID after temporary ID generation"
              Rails.logger.debug "Rider data: #{rider.to_json}"
              Rails.logger.debug "Horse data: #{horse.to_json}"
            end
            next
          end

          # Prepare result
          result = {
            'foreign_id' => "#{rider_fei_id}_#{horse_fei_id}_#{competition_foreign_id}",
            'rider' => { 'foreign_id' => rider_fei_id },
            'horse' => { 'foreign_id' => horse_fei_id },
            'rid' => true,
            'result_at' => Time.current.strftime('%Y-%m-%d %H:%M:%S'),
            'last_result_at' => Time.current.strftime('%Y-%m-%d %H:%M:%S'),
            'k' => 'H',
            'av' => 'A'
          }

          # For team competitions, check if this rider should skip rounds
          if is_team_competition && riders_to_skip[result['foreign_id']]
            skip_rounds = riders_to_skip[result['foreign_id']]
            skip_rounds.sort! # Ensure rounds are in order
            result['skip_rounds'] = skip_rounds # Array of integers
            Rails.logger.debug "Adding skip_rounds #{skip_rounds.to_json} for rider: #{result['foreign_id']}" if Rails.configuration.debug_mode
          end

          # Process round results
          result_details = competitor['RESULT'] || []
          result_total = competitor.dig('RESULTTOTAL', 0) || {}

          result['ord'] = (competitor['SORTORDER'] || 1000).to_i

          # Map results by round
          result_details.each do |round_result|
            round = round_result['ROUND'] || 0
            faults = (round_result['FAULTS'] || 0).to_f
            time = (round_result['TIME'] || 0).to_f
            time_faults = (round_result['TIMEFAULTS'] || 0).to_f

            case round
            when 1
              result['grundf'] = faults
              result['grundt'] = time
              result['tfg'] = time_faults
            when 2
              result['omh1f'] = faults
              result['omh1t'] = time
              result['tf1'] = time_faults
            when 3
              result['omh2f'] = faults
              result['omh2t'] = time
              result['tf2'] = time_faults
            when 4
              result['omg3f'] = faults
              result['omg3t'] = time
              result['tf3'] = time_faults
            when 5
              result['omg4f'] = faults
              result['omg4t'] = time
              result['tf4'] = time_faults
            end
          end

          # Total faults
          result['totfel'] = (result_total['FAULTS'] || 0).to_f

          # Handle special statuses
          has_special_status = false
          if result_total['STATUS'] && result_total['STATUS'] != 1
            status_text = (result_total['TEXT'] || '').downcase
            round_name = (result_total['NAME'] || '').downcase
            has_special_status = true

            case status_text
            when 'retired'
              result['or'] = 'U'
              result['result_preview'] = 'Ret.'
              result['grundf'] = 999
              result['grundt'] = 999
              result['tfg'] = nil
              result['re'] = eliminated_rank
            when 'eliminated'
              result['or'] = 'D'
              result['result_preview'] = 'El.'
              result['grundf'] = 999
              result['grundt'] = 999
              result['tfg'] = nil
              result['re'] = eliminated_rank
            when 'disqualified'
              result['or'] = 'S'
              result['result_preview'] = 'Dsq.'
              result['grundf'] = 999
              result['grundt'] = 999
              result['tfg'] = nil
              result['re'] = eliminated_rank
            when 'withdrawn'
              handle_withdrawn_status(result, round_name)
            when 'no show'
              result['a'] = 'U'
              result['grundf'] = 999
              result['grundt'] = 999
              result['tfg'] = nil
              result['result_preview'] = 'NS'
            end
          end

          # Handle team flags for team competitions
          if is_team_competition
            handle_team_flags(result, result_details, has_special_status, status_text)
          end

          # Add rank if not already set
          result['re'] ||= result_total['RANK'].to_i if result_total['RANK']

          # Prize money
          if result_total.dig('PRIZE', 'MONEY')
            result['premie'] = result_total.dig('PRIZE', 'MONEY').to_f
            result['premie_show'] = result_total.dig('PRIZE', 'MONEY').to_f
          end

          # Prize text
          if result_total.dig('PRIZE', 'TEXT') && !result_total.dig('PRIZE', 'MONEY')
            result['rtxt'] = result_total.dig('PRIZE', 'TEXT')
            result['premie'] = 0
            result['premie_show'] = 0
          end

          # No results at all
          if result_details.empty? && !result['or'] && !result['a']
            result['a'] = 'U'
            result['grundf'] = 999
            result['grundt'] = 999
            result['tfg'] = nil
            result['result_preview'] = 'NS'
          end

          results << result
        end

        # Prepare batch data
        batch_data = {}

        if competition_update.any?
          competition_update['foreign_id'] = competition_foreign_id
          batch_data['competitions'] = {
            'unique_by' => 'foreign_id',
            'records' => [competition_update]
          }
        end

        if results.any?
          batch_data['starts'] = {
            'unique_by' => 'foreign_id',
            'where' => { 'competition' => { 'foreign_id' => competition_foreign_id } },
            'replace' => true,
            'records' => results
          }
        end

        if batch_data.any?
          all_batch_data << {
            'competition' => comp['name'],
            'competition_foreign_id' => competition_foreign_id,
            'is_team' => is_team_competition,
            'data' => batch_data
          }
        end

        processed_competitions << {
          'name' => comp['name'],
          'foreign_id' => competition_foreign_id,
          'results_count' => results.size,
          'is_team' => is_team_competition,
          'time_allowed' => competition_update['grundt'],
          'time_allowed_jumpoff' => competition_update['omh1t'],
          'time_allowed_round3' => competition_update['omh2t'],
          'time_allowed_round4' => competition_update['omg3t'],
          'time_allowed_round5' => competition_update['omg4t'],
          'rounds' => results_data.dig('CLASS', 'ROUNDS') || [],
          'status' => results_data.dig('CLASS', 'STATUS') || 'unknown'
        }

      rescue => e
        processed_competitions << {
          'name' => comp['name'],
          'foreign_id' => competition_foreign_id,
          'results_count' => 0,
          'error' => e.message
        }
      end
    end

    {
      success: true,
      message: 'Results ready for import',
      processedCompetitions: processed_competitions,
      batchData: all_batch_data
    }
  end

  private

  def fetch_existing_people
    existing = {}

    response = HTTParty.get(
      "#{@meeting_url}/people.json",
      headers: {
        'X-Api-Key' => @api_key,
        'Accept' => 'application/json'
      },
      timeout: 10
    )

    if response.code == 200
      people = JSON.parse(response.body) rescue []
      people.each do |person|
        existing[person['foreign_id']] = person if person['foreign_id']
        existing[person['fei_id']] = person if person['fei_id']
      end
    end

    existing
  end

  def fetch_existing_horses
    existing = {}

    response = HTTParty.get(
      "#{@meeting_url}/horses.json",
      headers: {
        'X-Api-Key' => @api_key,
        'Accept' => 'application/json'
      },
      timeout: 10
    )

    if response.code == 200
      horses = JSON.parse(response.body) rescue []
      horses.each do |horse|
        existing[horse['foreign_id']] = horse if horse['foreign_id']
        existing[horse['fei_id']] = horse if horse['fei_id']
      end
    end

    existing
  end

  def fetch_existing_clubs
    existing = {}

    response = HTTParty.get(
      "#{@meeting_url}/clubs.json",
      headers: {
        'X-Api-Key' => @api_key,
        'Accept' => 'application/json'
      },
      timeout: 10
    )

    if response.code == 200
      clubs = JSON.parse(response.body) rescue []
      clubs.each do |club|
        existing[club['foreign_id']] = club if club['foreign_id']
      end
    end

    existing
  end

  def handle_withdrawn_status(result, round_name)
    if round_name.include?('jump-off') || round_name.include?('round 2') || round_name.include?('phase 2')
      result['omh1f'] = 999
      result['omh1t'] = 999
      result['totfel'] = 999
      result['result_preview'] = '0-ABST'
    elsif round_name.include?('round 3') || round_name.include?('phase 3')
      result['omh2f'] = 999
      result['omh2t'] = 999
      result['totfel'] = 999
      result['result_preview'] = '0-0-ABST'
    elsif round_name.include?('round 4') || round_name.include?('phase 4')
      result['omg3f'] = 999
      result['omg3t'] = 999
      result['totfel'] = 999
      result['result_preview'] = '0-0-0-ABST'
    elsif round_name.include?('round 5') || round_name.include?('phase 5')
      result['omg4f'] = 999
      result['omg4t'] = 999
      result['totfel'] = 999
      result['result_preview'] = '0-0-0-0-ABST'
    else
      result['a'] = 'Ö'
      result['grundf'] = 999
      result['grundt'] = 999
      result['tfg'] = nil
      result['result_preview'] = 'ABST'
    end
  end

  def handle_team_flags(result, result_details, has_special_status, status_text)
    rounds_data = {}
    result_details.each do |round_result|
      round = round_result['ROUND'] || 0
      rounds_data[round] = true if round > 0
    end

    # Round 1
    result['round1_in_team'] = result['grundf'] && result['grundf'] != 999

    if !has_special_status || (has_special_status && status_text == 'withdrawn')
      # Round 2
      if rounds_data[1] && !rounds_data[2] && !has_special_status
        result['omh1f'] = 999
        result['omh1t'] = 999
        result['round2_in_team'] = true
        result['or'] = 'A'
        result['totfel'] = 999 unless result['totfel'] && result['totfel'] >= 999
        result['result_preview'] = "#{result['grundf'] || 0}-ABST"
      elsif rounds_data[2]
        result['round2_in_team'] = true
      else
        result['round2_in_team'] = false
      end

      # Round 3
      if rounds_data[1] && rounds_data[2] && !rounds_data[3] && !has_special_status
        result['omh2f'] = 999
        result['omh2t'] = 999
        result['round3_in_team'] = true
        result['or'] = 'A'
        result['totfel'] = 999 unless result['totfel'] && result['totfel'] >= 999
        result['result_preview'] = "#{result['grundf'] || 0}-#{result['omh1f'] || 0}-ABST"
      elsif rounds_data[3]
        result['round3_in_team'] = true
      else
        result['round3_in_team'] = false
      end

      # Similar logic for rounds 4 and 5...
      result['round4_in_team'] = false
      result['round5_in_team'] = false
    else
      result['round2_in_team'] = false
      result['round3_in_team'] = false
      result['round4_in_team'] = false
      result['round5_in_team'] = false
    end
  end
end