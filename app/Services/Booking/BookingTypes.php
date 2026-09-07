<?php
declare(strict_types=1);

namespace App\Services\Booking;

/**
 * Presets for the kind of business that takes bookings. Each one seeds sensible services and the
 * questions the assistant should ask, so an owner can enable booking without designing a form.
 */
final class BookingTypes
{
    /**
     * key => [label, noun, verb, services[[name, minutes, price?]], questions[[key,label,type,options?,required]]]
     * Question types: text, textarea, choice, yesno, phone, email.
     */
    public const TYPES = [
        'general' => [
            'label' => 'General appointment',
            'noun' => 'appointment',
            'description' => 'A simple appointment with a service and a time.',
            'services' => [['name' => 'Appointment', 'minutes' => 30]],
            'questions' => [
                ['key' => 'reason', 'label' => 'What would you like to discuss?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'spa' => [
            'label' => 'Spa & wellness',
            'noun' => 'treatment',
            'description' => 'Massages, facials and treatments, usually with a therapist preference.',
            'services' => [
                ['name' => 'Swedish massage', 'minutes' => 60],
                ['name' => 'Deep tissue massage', 'minutes' => 60],
                ['name' => 'Hot stone massage', 'minutes' => 90],
                ['name' => 'Classic facial', 'minutes' => 45],
                ['name' => 'Body scrub', 'minutes' => 45],
                ['name' => 'Manicure & pedicure', 'minutes' => 60],
            ],
            'questions' => [
                ['key' => 'first_visit', 'label' => 'Is this your first visit with us?', 'type' => 'yesno', 'required' => false],
                ['key' => 'therapist', 'label' => 'Do you have a preferred therapist?', 'type' => 'text', 'required' => false],
                ['key' => 'pressure', 'label' => 'Preferred pressure', 'type' => 'choice', 'options' => ['Light', 'Medium', 'Firm'], 'required' => false],
                ['key' => 'health', 'label' => 'Any injuries, allergies, pregnancy or health conditions we should know about?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'medical' => [
            'label' => "Doctor's office / medical practice",
            'noun' => 'appointment',
            'description' => 'Consultations and check-ups with patient intake questions.',
            'services' => [
                ['name' => 'New patient consultation', 'minutes' => 30],
                ['name' => 'Follow-up visit', 'minutes' => 15],
                ['name' => 'Annual check-up', 'minutes' => 30],
                ['name' => 'Vaccination', 'minutes' => 15],
                ['name' => 'Telehealth call', 'minutes' => 20],
            ],
            'questions' => [
                ['key' => 'new_patient', 'label' => 'Are you a new patient?', 'type' => 'yesno', 'required' => true],
                ['key' => 'reason', 'label' => 'What is the reason for your visit?', 'type' => 'textarea', 'required' => true],
                ['key' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'text', 'required' => false],
                ['key' => 'insurance', 'label' => 'Insurance provider (if any)', 'type' => 'text', 'required' => false],
                ['key' => 'doctor', 'label' => 'Is there a doctor you would like to see?', 'type' => 'text', 'required' => false],
            ],
        ],
        'dental' => [
            'label' => 'Dental practice',
            'noun' => 'appointment',
            'description' => 'Cleanings, check-ups and dental treatments.',
            'services' => [
                ['name' => 'Check-up & cleaning', 'minutes' => 45],
                ['name' => 'New patient exam', 'minutes' => 60],
                ['name' => 'Emergency / toothache', 'minutes' => 30],
                ['name' => 'Teeth whitening', 'minutes' => 60],
                ['name' => 'Filling', 'minutes' => 45],
            ],
            'questions' => [
                ['key' => 'new_patient', 'label' => 'Are you a new patient?', 'type' => 'yesno', 'required' => true],
                ['key' => 'reason', 'label' => 'Any pain or specific concern?', 'type' => 'textarea', 'required' => false],
                ['key' => 'last_visit', 'label' => 'Roughly when was your last dental visit?', 'type' => 'text', 'required' => false],
                ['key' => 'insurance', 'label' => 'Insurance provider (if any)', 'type' => 'text', 'required' => false],
            ],
        ],
        'salon' => [
            'label' => 'Hair & beauty salon',
            'noun' => 'appointment',
            'description' => 'Cuts, colour and beauty services with a stylist preference.',
            'services' => [
                ['name' => 'Haircut', 'minutes' => 45],
                ['name' => 'Cut & blow dry', 'minutes' => 60],
                ['name' => 'Colour', 'minutes' => 120],
                ['name' => 'Highlights', 'minutes' => 150],
                ['name' => 'Blow dry', 'minutes' => 30],
                ['name' => 'Nails', 'minutes' => 45],
            ],
            'questions' => [
                ['key' => 'stylist', 'label' => 'Do you have a preferred stylist?', 'type' => 'text', 'required' => false],
                ['key' => 'hair_length', 'label' => 'Hair length', 'type' => 'choice', 'options' => ['Short', 'Medium', 'Long'], 'required' => false],
                ['key' => 'notes', 'label' => 'Anything you would like us to know about the look you want?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'barber' => [
            'label' => 'Barber shop',
            'noun' => 'appointment',
            'description' => 'Cuts, beard trims and shaves.',
            'services' => [
                ['name' => 'Haircut', 'minutes' => 30],
                ['name' => 'Beard trim', 'minutes' => 20],
                ['name' => 'Cut & beard', 'minutes' => 45],
                ['name' => 'Hot towel shave', 'minutes' => 30],
                ['name' => 'Kids cut', 'minutes' => 20],
            ],
            'questions' => [
                ['key' => 'barber', 'label' => 'Do you have a preferred barber?', 'type' => 'text', 'required' => false],
                ['key' => 'style', 'label' => 'What style are you after?', 'type' => 'text', 'required' => false],
            ],
        ],
        'therapy' => [
            'label' => 'Therapy & physiotherapy',
            'noun' => 'session',
            'description' => 'Physio, chiropractic, counselling and similar sessions.',
            'services' => [
                ['name' => 'Initial assessment', 'minutes' => 60],
                ['name' => 'Follow-up session', 'minutes' => 45],
                ['name' => 'Sports massage', 'minutes' => 60],
                ['name' => 'Online session', 'minutes' => 50],
            ],
            'questions' => [
                ['key' => 'first_visit', 'label' => 'Is this your first session with us?', 'type' => 'yesno', 'required' => true],
                ['key' => 'concern', 'label' => 'What would you like help with?', 'type' => 'textarea', 'required' => true],
                ['key' => 'referral', 'label' => 'Do you have a referral from a doctor?', 'type' => 'yesno', 'required' => false],
            ],
        ],
        'veterinary' => [
            'label' => 'Veterinary clinic',
            'noun' => 'appointment',
            'description' => 'Pet check-ups, vaccinations and grooming.',
            'services' => [
                ['name' => 'General consultation', 'minutes' => 30],
                ['name' => 'Vaccination', 'minutes' => 20],
                ['name' => 'Annual health check', 'minutes' => 30],
                ['name' => 'Grooming', 'minutes' => 60],
                ['name' => 'Dental check', 'minutes' => 30],
            ],
            'questions' => [
                ['key' => 'pet_name', 'label' => "Your pet's name", 'type' => 'text', 'required' => true],
                ['key' => 'species', 'label' => 'What kind of animal is it?', 'type' => 'text', 'required' => true],
                ['key' => 'age', 'label' => 'How old is your pet?', 'type' => 'text', 'required' => false],
                ['key' => 'symptoms', 'label' => 'Any symptoms or concerns?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'restaurant' => [
            'label' => 'Restaurant table reservation',
            'noun' => 'reservation',
            'description' => 'Table bookings with party size and seating preference.',
            'services' => [
                ['name' => 'Lunch', 'minutes' => 90],
                ['name' => 'Dinner', 'minutes' => 120],
                ['name' => 'Private event enquiry', 'minutes' => 60],
            ],
            'questions' => [
                ['key' => 'party_size', 'label' => 'How many people are dining?', 'type' => 'text', 'required' => true],
                ['key' => 'seating', 'label' => 'Seating preference', 'type' => 'choice', 'options' => ['No preference', 'Indoor', 'Outdoor', 'Bar', 'Quiet corner'], 'required' => false],
                ['key' => 'occasion', 'label' => 'Any special occasion?', 'type' => 'text', 'required' => false],
                ['key' => 'dietary', 'label' => 'Allergies or dietary requirements?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'fitness' => [
            'label' => 'Fitness & personal training',
            'noun' => 'session',
            'description' => 'Personal training, classes and gym inductions.',
            'services' => [
                ['name' => 'Personal training session', 'minutes' => 60],
                ['name' => 'Free consultation', 'minutes' => 30],
                ['name' => 'Gym induction', 'minutes' => 45],
                ['name' => 'Group class', 'minutes' => 45],
            ],
            'questions' => [
                ['key' => 'goal', 'label' => 'What is your main goal?', 'type' => 'text', 'required' => false],
                ['key' => 'experience', 'label' => 'Training experience', 'type' => 'choice', 'options' => ['Beginner', 'Some experience', 'Advanced'], 'required' => false],
                ['key' => 'injuries', 'label' => 'Any injuries or medical conditions?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'automotive' => [
            'label' => 'Car service & repair',
            'noun' => 'booking',
            'description' => 'Servicing, MOT and repairs with vehicle details.',
            'services' => [
                ['name' => 'Full service', 'minutes' => 120],
                ['name' => 'Interim service', 'minutes' => 60],
                ['name' => 'MOT / inspection', 'minutes' => 60],
                ['name' => 'Tyre change', 'minutes' => 45],
                ['name' => 'Diagnostic check', 'minutes' => 60],
            ],
            'questions' => [
                ['key' => 'vehicle', 'label' => 'Make and model of your vehicle', 'type' => 'text', 'required' => true],
                ['key' => 'registration', 'label' => 'Registration / plate number', 'type' => 'text', 'required' => false],
                ['key' => 'mileage', 'label' => 'Approximate mileage', 'type' => 'text', 'required' => false],
                ['key' => 'issue', 'label' => 'What seems to be the problem?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'home_services' => [
            'label' => 'Home services (plumber, electrician, cleaning)',
            'noun' => 'visit',
            'description' => 'On-site visits that need an address and a description of the job.',
            'services' => [
                ['name' => 'Callout / assessment', 'minutes' => 60],
                ['name' => 'Standard job', 'minutes' => 120],
                ['name' => 'Emergency callout', 'minutes' => 60],
                ['name' => 'Quote visit', 'minutes' => 30],
            ],
            'questions' => [
                ['key' => 'address', 'label' => 'Address for the visit', 'type' => 'textarea', 'required' => true],
                ['key' => 'job', 'label' => 'What needs doing?', 'type' => 'textarea', 'required' => true],
                ['key' => 'property', 'label' => 'Property type', 'type' => 'choice', 'options' => ['House', 'Apartment', 'Office', 'Other'], 'required' => false],
                ['key' => 'access', 'label' => 'Any access notes (parking, gate code, pets)?', 'type' => 'text', 'required' => false],
            ],
        ],
        'legal' => [
            'label' => 'Law firm consultation',
            'noun' => 'consultation',
            'description' => 'Initial consultations by matter type.',
            'services' => [
                ['name' => 'Initial consultation', 'minutes' => 45],
                ['name' => 'Follow-up meeting', 'minutes' => 30],
                ['name' => 'Phone consultation', 'minutes' => 30],
            ],
            'questions' => [
                ['key' => 'matter', 'label' => 'What area of law does your matter concern?', 'type' => 'text', 'required' => true],
                ['key' => 'summary', 'label' => 'Briefly, what do you need help with?', 'type' => 'textarea', 'required' => true],
                ['key' => 'deadline', 'label' => 'Is there a deadline or court date?', 'type' => 'text', 'required' => false],
            ],
        ],
        'realestate' => [
            'label' => 'Property viewing',
            'noun' => 'viewing',
            'description' => 'Viewings and valuations for estate agents.',
            'services' => [
                ['name' => 'Property viewing', 'minutes' => 30],
                ['name' => 'Valuation visit', 'minutes' => 45],
                ['name' => 'Video tour', 'minutes' => 20],
            ],
            'questions' => [
                ['key' => 'property', 'label' => 'Which property are you interested in?', 'type' => 'text', 'required' => true],
                ['key' => 'buying_or_renting', 'label' => 'Are you buying or renting?', 'type' => 'choice', 'options' => ['Buying', 'Renting', 'Just looking'], 'required' => false],
                ['key' => 'timeline', 'label' => 'How soon are you looking to move?', 'type' => 'text', 'required' => false],
            ],
        ],
        'education' => [
            'label' => 'Tutoring & lessons',
            'noun' => 'lesson',
            'description' => 'Tutoring, music lessons and driving lessons.',
            'services' => [
                ['name' => 'Trial lesson', 'minutes' => 30],
                ['name' => 'Single lesson', 'minutes' => 60],
                ['name' => 'Online lesson', 'minutes' => 60],
            ],
            'questions' => [
                ['key' => 'subject', 'label' => 'Which subject or instrument?', 'type' => 'text', 'required' => true],
                ['key' => 'level', 'label' => 'Current level', 'type' => 'choice', 'options' => ['Beginner', 'Intermediate', 'Advanced'], 'required' => false],
                ['key' => 'student_age', 'label' => 'Age of the student', 'type' => 'text', 'required' => false],
                ['key' => 'goals', 'label' => 'What would you like to achieve?', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'consulting' => [
            'label' => 'Business consultation / demo',
            'noun' => 'meeting',
            'description' => 'Discovery calls, demos and strategy sessions.',
            'services' => [
                ['name' => 'Discovery call', 'minutes' => 30],
                ['name' => 'Product demo', 'minutes' => 45],
                ['name' => 'Strategy session', 'minutes' => 60],
            ],
            'questions' => [
                ['key' => 'company', 'label' => 'Company name', 'type' => 'text', 'required' => false],
                ['key' => 'role', 'label' => 'Your role', 'type' => 'text', 'required' => false],
                ['key' => 'goal', 'label' => 'What would you like to get out of the call?', 'type' => 'textarea', 'required' => true],
                ['key' => 'team_size', 'label' => 'How big is your team?', 'type' => 'text', 'required' => false],
            ],
        ],
        'photography' => [
            'label' => 'Photography & studio',
            'noun' => 'shoot',
            'description' => 'Portrait, event and product shoots.',
            'services' => [
                ['name' => 'Portrait session', 'minutes' => 60],
                ['name' => 'Family shoot', 'minutes' => 90],
                ['name' => 'Product shoot', 'minutes' => 120],
                ['name' => 'Consultation call', 'minutes' => 30],
            ],
            'questions' => [
                ['key' => 'shoot_type', 'label' => 'What kind of shoot is it?', 'type' => 'text', 'required' => true],
                ['key' => 'people', 'label' => 'How many people will be photographed?', 'type' => 'text', 'required' => false],
                ['key' => 'location', 'label' => 'Studio or on location?', 'type' => 'choice', 'options' => ['Studio', 'On location', 'Not sure yet'], 'required' => false],
            ],
        ],
        'tattoo' => [
            'label' => 'Tattoo & piercing studio',
            'noun' => 'appointment',
            'description' => 'Consultations and sittings with design details.',
            'services' => [
                ['name' => 'Free consultation', 'minutes' => 30],
                ['name' => 'Small tattoo', 'minutes' => 60],
                ['name' => 'Half day sitting', 'minutes' => 240],
                ['name' => 'Piercing', 'minutes' => 30],
            ],
            'questions' => [
                ['key' => 'artist', 'label' => 'Do you have a preferred artist?', 'type' => 'text', 'required' => false],
                ['key' => 'design', 'label' => 'Describe the design you have in mind', 'type' => 'textarea', 'required' => true],
                ['key' => 'placement', 'label' => 'Where on the body?', 'type' => 'text', 'required' => false],
                ['key' => 'size', 'label' => 'Approximate size', 'type' => 'text', 'required' => false],
                ['key' => 'over_18', 'label' => 'Are you 18 or older?', 'type' => 'yesno', 'required' => true],
            ],
        ],
    ];

    public static function all(): array
    {
        return self::TYPES;
    }

    public static function get(string $key): array
    {
        return self::TYPES[$key] ?? self::TYPES['general'];
    }

    public static function noun(string $key): string
    {
        return (string) (self::TYPES[$key]['noun'] ?? 'appointment');
    }

    /** Option list for a select box. */
    public static function options(): array
    {
        $out = [];
        foreach (self::TYPES as $key => $type) {
            $out[$key] = $type['label'];
        }
        return $out;
    }
}
