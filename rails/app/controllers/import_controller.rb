# app/controllers/import_controller.rb

class ImportController < ApplicationController
  require 'net/http'
  require 'json'

  def index
    # Cette méthode affiche la vue de recherche
  end

  def fetch_event_info
    show_id = params[:show_id]
    return render json: { success: false, error: 'Show ID is required' } if show_id.blank?

    begin
      hippodata_url = "https://api.hippo-server.net/scoring/event/#{show_id}"
      uri = URI(hippodata_url)
      req = Net::HTTP::Get.new(uri)
      req['Authorization'] = "Bearer #{ENV['HIPPODATA_BEARER']}"
      req['Accept'] = 'application/json'

      res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) { |http| http.request(req) }

      if res.code.to_i != 200
        raise "Failed to fetch data from Hippodata (HTTP #{res.code})"
      end

      hippodata_data = JSON.parse(res.body)

      event_info = {
        id: hippodata_data['EVENT']['ID'] || show_id,
        name: hippodata_data['EVENT']['CAPTION'] || '',
        venue: hippodata_data['EVENT']['LOCATION'] || ''
      }

      classes = hippodata_data['CLASSES']['CLASS'].map do |cls|
        {
          id: cls['ID'],
          nr: cls['NR'] || cls['ID'],
          name: cls['NAME'] || cls['SPONSOR'],
          date: cls['DATE'],
          category: cls['CATEGORY'] || '',
          prize_money: cls['PRIZE']['MONEY'] || 0,
          prize_currency: cls['PRIZE']['CURRENCY'] || 'EUR',
          status: cls['STATUS'] || 'unknown'
        }
      end

      render json: { success: true, event: event_info, classes: classes }
    rescue => e
      render json: { success: false, error: e.message }
    end
  end

  def get_imported_status
    meeting_url = params[:meeting_url]
    api_key = params[:api_key]
    return render json: { success: false, error: 'Missing parameters' } if meeting_url.blank? || api_key.blank?

    begin
      imported = { classes: [], startlists: [], results: [] }

      # Charger les compétitions existantes
      competitions_url = "#{meeting_url}/competitions.json"
      uri = URI(competitions_url)
      req = Net::HTTP::Get.new(uri)
      req['X-Api-Key'] = api_key
      req['Accept'] = 'application/json'

      res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) { |http| http.request(req) }
      data = JSON.parse(res.body)

      data.each do |competition|
        if competition['foreignid']
          foreign_id = competition['foreignid']
          imported[:classes] << foreign_id

          # Vérifier si startlist existe
          start_url = "#{meeting_url}/competitions/#{foreign_id}/starts.json"
          uri = URI(start_url)
          req = Net::HTTP::Get.new(uri)
          req['X-Api-Key'] = api_key
          req['Accept'] = 'application/json'

          res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) { |http| http.request(req) }
          starts = JSON.parse(res.body)
          imported[:startlists] << foreign_id if starts.is_a?(Array) && starts.any?

          # Vérifier si résultats existent
          results_url = "#{meeting_url}/competitions/#{foreign_id}/H/results.json"
          uri = URI(results_url)
          req = Net::HTTP::Get.new(uri)
          req['X-Api-Key'] = api_key
          req['Accept'] = 'application/json'

          res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) { |http| http.request(req) }
          results = JSON.parse(res.body)
          if res.code.to_i == 200 && results.is_a?(Array) && results.any? { |result| result['grundf'] || result['grundt'] }
            imported[:results] << foreign_id
          end
        end
      end

      render json: { success: true, existing: imported }
    rescue => e
      render json: { success: false, error: e.message }
    end
  end

  def import_selected
    show_id = params[:show_id]
    selections = JSON.parse(params[:selections])

    if show_id.blank? || selections.blank?
      return render json: { success: false, error: 'Show ID and selections are required' }
    end

    begin
      # Vous pouvez ici commencer à traiter les sélections pour importer les classes, startlists, et résultats.
      render json: { success: true, message: 'Import process started', selections: selections }
    rescue => e
      render json: { success: false, error: e.message }
    end
  end

  def send_batch_to_equipe
    batch_data = JSON.parse(params[:batch_data])
    api_key = params[:api_key]
    meeting_url = params[:meeting_url]
    transaction_uuid = params[:transaction_uuid]

    if batch_data.blank? || api_key.blank? || meeting_url.blank?
      return render json: { success: false, error: 'Missing required parameters' }
    end

    begin
      batch_url = "#{meeting_url}/batch"
      uri = URI(batch_url)
      req = Net::HTTP::Post.new(uri)
      req['X-Api-Key'] = api_key
      req['X-Transaction-Uuid'] = transaction_uuid
      req['Content-Type'] = 'application/json'
      req.body = batch_data.to_json

      res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) { |http| http.request(req) }

      if res.code.to_i == 200 || res.code.to_i == 201
        render json: { success: true, response: JSON.parse(res.body), httpCode: res.code }
      else
        render json: { success: false, error: "HTTP #{res.code}", response: res.body, httpCode: res.code }
      end
    rescue => e
      render json: { success: false, error: e.message }
    end
  end
end
