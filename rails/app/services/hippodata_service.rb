class HippodataService
  include HTTParty
  base_uri 'https://api.hippo-server.net'
  
  def initialize
    @bearer_token = ENV['HIPPODATA_BEARER']
  end
  
  def fetch_event(show_id)
    response = self.class.get(
      "/scoring/event/#{show_id}",
      headers: {
        'Authorization' => "Bearer #{@bearer_token}",
        'Accept' => 'application/json'
      }
    )
    
    if response.code != 200
      raise "Failed to fetch data from Hippodata (HTTP #{response.code})"
    end
    
    JSON.parse(response.body)
  end
  
  def fetch_startlist(event_id, class_id)
    response = self.class.get(
      "/scoring/event/#{event_id}/startlist/#{class_id}/all",
      headers: {
        'Authorization' => "Bearer #{@bearer_token}",
        'Accept' => 'application/json'
      },
      timeout: 15
    )
    
    if response.code != 200
      raise "Failed to fetch startlist for class #{class_id} (HTTP #{response.code})"
    end
    
    JSON.parse(response.body)
  end
  
  def fetch_results(event_id, class_id)
    response = self.class.get(
      "/scoring/event/#{event_id}/resultlist/#{class_id}",
      headers: {
        'Authorization' => "Bearer #{@bearer_token}",
        'Accept' => 'application/json'
      },
      timeout: 15
    )
    
    if response.code != 200
      raise "Failed to fetch results for class #{class_id} (HTTP #{response.code})"
    end
    
    JSON.parse(response.body)
  end
end