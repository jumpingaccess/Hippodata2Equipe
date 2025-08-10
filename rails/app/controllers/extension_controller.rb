class ExtensionController < ApplicationController
  skip_before_action :verify_authenticity_token, only: [:index]
  
  def index
    @decoded = decode_request
    @debug_mode = ENV['DEBUG'] == '1'
    
    if @decoded && @decoded.dig('payload', 'target').in?(['modal', 'browser'])
      render :index
    else
      render plain: "Invalid request", status: 400
    end
  end
  
  private
  
  def decode_request
    if request.post?
      # POST request - read body
      JSON.parse(request.body.read)
    else
      # GET request - decode JWT
      jwt_token = params[:token]
      return nil if jwt_token.blank?
      
      begin
        decoded_token = JWT.decode(
          jwt_token, 
          ENV['EQUIPE_SECRET'], 
          true, 
          { algorithm: 'HS256' }
        )
        decoded_token.first
      rescue JWT::DecodeError => e
        Rails.logger.error "JWT decode error: #{e.message}"
        nil
      end
    end
  end
end