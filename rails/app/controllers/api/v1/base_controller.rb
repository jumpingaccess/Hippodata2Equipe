module Api
  module V1
    class BaseController < ApplicationController
      skip_before_action :verify_authenticity_token
      
      before_action :set_json_format
      
      rescue_from StandardError do |e|
        render json: { success: false, error: e.message }, status: :internal_server_error
      end
      
      private
      
      def set_json_format
        request.format = :json
      end
    end
  end
end