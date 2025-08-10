module Api
  module V1
    class ImportsController < BaseController
      def get_imported_status
        meeting_url = params[:meeting_url]
        api_key = params[:api_key]
        
        if meeting_url.blank? || api_key.blank?
          render json: { success: false, error: 'Missing parameters' }
          return
        end
        
        existing = {
          classes: [],
          startlists: [],
          results: []
        }
        
        # Load existing competitions
        response = HTTParty.get(
          "#{meeting_url.chomp('/')}/competitions.json",
          headers: {
            'X-Api-Key' => api_key,
            'Accept' => 'application/json'
          }
        )
        
        competitions = JSON.parse(response.body) rescue []
        
        competitions.each do |comp|
          next if comp['foreignid'].blank?
          
          foreign_id = comp['foreignid']
          class_id = comp['kq']
          existing[:classes] << foreign_id
          
          is_team_competition = comp['lag'] == true
          
          # Check for startlist
          start_response = HTTParty.get(
            "#{meeting_url.chomp('/')}/competitions/#{class_id}/starts.json",
            headers: {
              'X-Api-Key' => api_key,
              'Accept' => 'application/json'
            },
            timeout: 10
          )
          
          if start_response.code == 200
            starts = JSON.parse(start_response.body) rescue []
            
            if starts.present? && starts.is_a?(Array)
              if is_team_competition
                has_team_starts = starts.any? { |start| start['lag_id'].present? || start['team'].present? }
                existing[:startlists] << foreign_id if has_team_starts || starts.any?
              else
                existing[:startlists] << foreign_id
              end
            end
          end
          
          # Check for results
          results_response = HTTParty.get(
            "#{meeting_url.chomp('/')}/competitions/#{class_id}/H/results.json",
            headers: {
              'X-Api-Key' => api_key,
              'Accept' => 'application/json'
            },
            timeout: 10
          )
          
          if results_response.code == 200
            results = JSON.parse(results_response.body) rescue []
            
            has_results = results.any? do |result|
              (result['grundf'].present? && result['grundf'].is_a?(Numeric) && result['grundf'] != '') ||
              (result['grundt'].present? && result['grundt'].is_a?(Numeric) && result['grundt'] != '')
            end
            
            existing[:results] << foreign_id if has_results
          end
        end
        
        render json: { success: true, existing: existing }
      rescue => e
        render json: { success: false, error: e.message }
      end
      
      def fetch_event_info
        show_id = params[:show_id]
        
        if show_id.blank?
          render json: { success: false, error: 'Show ID is required' }
          return
        end
        
        hippodata_service = HippodataService.new
        event_data = hippodata_service.fetch_event(show_id)
        
        classes = event_data.dig('CLASSES', 'CLASS')&.map do |cls|
          name = cls['NAME'].presence || cls['SPONSOR']
          
          {
            id: cls['ID'],
            nr: cls['NR'] || cls['ID'],
            name: name,
            date: cls['DATE'],
            category: cls['CATEGORY'] || '',
            prize_money: cls.dig('PRIZE', 'MONEY') || 0,
            prize_currency: cls.dig('PRIZE', 'CURRENCY') || 'EUR',
            status: cls['STATUS'] || 'unknown'
          }
        end || []
        
        render json: {
          success: true,
          event: {
            id: event_data.dig('EVENT', 'ID') || show_id,
            name: event_data.dig('EVENT', 'CAPTION') || '',
            venue: event_data.dig('EVENT', 'LOCATION') || ''
          },
          classes: classes
        }
      rescue => e
        render json: { success: false, error: e.message }
      end
      
      def import_selected
        show_id = params[:show_id]
        api_key = params[:api_key]
        meeting_url = params[:meeting_url]
        selections = JSON.parse(params[:selections] || '[]')
        
        if show_id.blank? || selections.empty?
          render json: { success: false, error: 'Show ID and selections are required' }
          return
        end
        
        import_service = ImportService.new(api_key: api_key, meeting_url: meeting_url)
        result = import_service.import_classes(show_id, selections)
        
        render json: result
      rescue => e
        render json: { success: false, error: e.message }
      end
      
      def send_batch_to_equipe
        batch_data = JSON.parse(params[:batch_data] || '{}')
        api_key = params[:api_key]
        meeting_url = params[:meeting_url]
        transaction_uuid = params[:transaction_uuid]
        
        if batch_data.empty? || api_key.blank? || meeting_url.blank?
          render json: { success: false, error: 'Missing required parameters' }
          return
        end
        
        response = HTTParty.post(
          "#{meeting_url}/batch",
          body: batch_data.to_json,
          headers: {
            'X-Api-Key' => api_key,
            'X-Transaction-Uuid' => transaction_uuid,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
          }
        )
        
        if response.code.in?([200, 201])
          render json: {
            success: true,
            response: JSON.parse(response.body),
            httpCode: response.code
          }
        else
          render json: {
            success: false,
            error: "HTTP #{response.code}",
            response: response.body,
            httpCode: response.code
          }
        end
      rescue => e
        render json: { success: false, error: e.message }
      end
      
      def import_startlists
        event_id = params[:event_id]
        api_key = params[:api_key]
        meeting_url = params[:meeting_url]
        competitions = JSON.parse(params[:competitions] || '[]')
        
        if event_id.blank? || competitions.empty?
          render json: { success: false, error: 'Event ID and competitions are required' }
          return
        end
        
        import_service = ImportService.new(api_key: api_key, meeting_url: meeting_url)
        result = import_service.import_startlists(event_id, competitions)
        
        render json: result
      rescue => e
        render json: { success: false, error: e.message }
      end
      
      def import_results
        event_id = params[:event_id]
        api_key = params[:api_key]
        meeting_url = params[:meeting_url]
        competitions = JSON.parse(params[:competitions] || '[]')
        
        if event_id.blank? || competitions.empty?
          render json: { success: false, error: 'Event ID and competitions are required' }
          return
        end
        
        import_service = ImportService.new(api_key: api_key, meeting_url: meeting_url)
        result = import_service.import_results(event_id, competitions)
        
        render json: result
      rescue => e
        render json: { success: false, error: e.message }
      end
    end
  end
end