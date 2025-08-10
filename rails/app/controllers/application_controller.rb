
class ApplicationController < ActionController::Base
  protect_from_forgery with: :exception
  
  before_action :set_cors_headers
  
  private
  
  def set_cors_headers
    headers['Access-Control-Allow-Origin'] = 'https://app.equipe.com'
    headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'
    headers['Access-Control-Allow-Headers'] = 'Origin, Content-Type, Accept, Authorization, X-Api-Key, X-Transaction-Uuid'
  end
end