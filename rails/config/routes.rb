Rails.application.routes.draw do
  # Define your application routes per the DSL in https://guides.rubyonrails.org/routing.html

  # API endpoints
  namespace :api do
    namespace :v1 do
      resources :imports, only: [] do
        collection do
          post :get_imported_status
          post :fetch_event_info
          post :import_selected
          post :send_batch_to_equipe
          post :import_startlists
          post :import_results
        end
      end
    end
  end

  # Main entry point
  root "extension#index"
  get "extension", to: "extension#index"
  post "extension", to: "extension#index"
  
  # Health check
  get "up" => "rails/health#show", as: :rails_health_check
end